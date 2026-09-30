<?php

// One-time, guarded repair of the September 12 X Inbox import. Run in the Mixpost container.
// Default: validate and write a full preimage backup. Pass --apply only after copying the backup to the host.

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

$audit = json_decode(file_get_contents('/tmp/x-engaged-post-authorship.json'), true, 512, JSON_THROW_ON_ERROR);
$groups = ['owned', 'foreign', 'unresolved'];
$items = [];
foreach ($groups as $group) {
    foreach ($audit[$group] as $row) {
        $items[(int) $row['imported_post_id']] = $row + ['classification' => $group];
    }
}

if (count($items) !== 158 || count($audit['owned']) !== 24 || count($audit['foreign']) !== 133 || count($audit['unresolved']) !== 1) {
    throw new RuntimeException('The saved X author audit no longer has the expected cohort.');
}

$rows = DB::table('mixpost_imported_posts as p')
    ->join('mixpost_accounts as a', 'a.id', '=', 'p.account_id')
    ->join('mixpost_inbox_conversations as c', 'c.imported_post_id', '=', 'p.id')
    ->whereIn('p.id', array_keys($items))
    ->select('p.*', 'a.provider', 'a.provider_id as account_provider_id', 'c.id as conversation_id')
    ->get();

if ($rows->count() !== 158) {
    throw new RuntimeException('One or more imported posts or Inbox conversations changed.');
}

foreach ($rows as $row) {
    $item = $items[$row->id] ?? null;
    if (! $item || $row->provider !== 'twitter' || $row->account_id !== $item['account_id'] ||
        (string) $row->provider_post_id !== $item['provider_post_id'] ||
        (string) $row->account_provider_id !== $item['account_provider_id']) {
        throw new RuntimeException('Account/post identity changed for imported post '.$row->id);
    }
    if ($row->data !== null || $row->created_at < '2026-09-12 09:00:00' || $row->created_at >= '2026-09-12 10:00:00') {
        throw new RuntimeException('Imported post '.$row->id.' was already changed.');
    }
    if ($item['classification'] === 'owned' && $item['author_id'] !== $item['account_provider_id']) {
        throw new RuntimeException('Owned author mismatch for '.$row->id);
    }
    if ($item['classification'] === 'foreign' && $item['author_id'] === $item['account_provider_id']) {
        throw new RuntimeException('Foreign author mismatch for '.$row->id);
    }
}

$backupPath = '/tmp/x-engaged-post-preimage-backup.json';
file_put_contents($backupPath, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
chmod($backupPath, 0600);

if (! in_array('--apply', $argv, true)) {
    echo json_encode(['status' => 'validated', 'rows' => $rows->count(), 'backup' => $backupPath]).PHP_EOL;
    exit;
}

$now = Carbon::now('UTC');
$metricTypes = [
    1 => 'impression_count',
    2 => 'like_count',
    3 => 'retweet_count',
    4 => 'reply_count',
    5 => 'quote_count',
    6 => 'bookmark_count',
];

DB::transaction(function () use ($items, $rows, $metricTypes, $now) {
    $insights = [];
    $history = [];

    foreach ($rows as $row) {
        $item = $items[$row->id];
        $owned = $item['classification'] === 'owned';
        $data = [
            'source' => 'inbox_engagement',
            'author_id' => $item['author_id'],
            'analytics_visible' => $owned,
        ];
        $changes = ['data' => json_encode($data, JSON_THROW_ON_ERROR)];
        if ($item['post_created_at']) {
            $changes['created_at'] = Carbon::parse($item['post_created_at'], 'UTC')->format('Y-m-d H:i:s');
        }

        $updated = DB::table('mixpost_imported_posts')
            ->where('id', $row->id)
            ->whereNull('data')
            ->where('created_at', $row->created_at)
            ->update($changes);
        if ($updated !== 1) {
            throw new RuntimeException('Concurrent update of imported post '.$row->id);
        }

        if (! $owned || ! $item['public_metrics']) {
            continue;
        }

        foreach ($metricTypes as $type => $field) {
            if (! array_key_exists($field, $item['public_metrics'])) {
                continue;
            }
            $common = [
                'workspace_id' => $row->workspace_id,
                'account_id' => $row->account_id,
                'provider_post_id' => $row->provider_post_id,
                'type' => $type,
                'value' => (int) $item['public_metrics'][$field],
            ];
            $insights[] = $common + ['updated_at' => $now];
            $history[] = $common + ['date' => $now->toDateString()];
        }
    }

    DB::table('mixpost_twitter_post_insights')->upsert(
        $insights,
        ['workspace_id', 'account_id', 'provider_post_id', 'type'],
        ['value', 'updated_at']
    );
    DB::table('mixpost_twitter_post_insight_history')->upsert(
        $history,
        ['workspace_id', 'account_id', 'provider_post_id', 'type', 'date'],
        ['value']
    );
});

echo json_encode([
    'status' => 'applied',
    'owned' => 24,
    'foreign' => 133,
    'unresolved' => 1,
    'backup' => $backupPath,
], JSON_UNESCAPED_SLASHES).PHP_EOL;
