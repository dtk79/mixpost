<?php

// One-time repair of the remaining X Inbox posts with no metrics. Run in the Mixpost container.
// Default: validate and write a preimage backup. Pass --apply only after saving that backup to the host.

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

$audit = json_decode(file_get_contents('/tmp/x-unclassified-inbox-authorship.json'), true, 512, JSON_THROW_ON_ERROR);
$classified = [];
foreach (['owned', 'foreign', 'unresolved'] as $group) {
    foreach ($audit[$group] as $item) {
        $classified[(int) $item['imported_post_id']] = $item + ['classification' => $group];
    }
}

if (count($classified) !== 99 || count($audit['owned']) !== 65 ||
    count($audit['foreign']) !== 33 || count($audit['unresolved']) !== 1) {
    throw new RuntimeException('The saved author audit no longer has the expected cohort.');
}

$rows = DB::table('mixpost_imported_posts as p')
    ->join('mixpost_accounts as a', 'a.id', '=', 'p.account_id')
    ->join('mixpost_inbox_conversations as c', 'c.imported_post_id', '=', 'p.id')
    ->where('a.provider', 'twitter')
    ->whereNull('p.data')
    ->whereIn('p.id', array_keys($classified))
    ->whereNotExists(function ($query) {
        $query->selectRaw('1')->from('mixpost_twitter_post_insights as i')
            ->whereColumn('i.account_id', 'p.account_id')
            ->whereColumn('i.provider_post_id', 'p.provider_post_id');
    })
    ->select('p.*', 'a.provider_id as account_provider_id')
    ->distinct()->get();

if ($rows->count() !== 36) {
    throw new RuntimeException('The unclassified no-metrics cohort changed.');
}

$counts = ['owned' => 0, 'foreign' => 0, 'unresolved' => 0];
foreach ($rows as $row) {
    $item = $classified[$row->id] ?? null;
    if (! $item || $row->account_id !== $item['account_id'] ||
        (string) $row->provider_post_id !== $item['provider_post_id'] ||
        (string) $row->account_provider_id !== $item['account_provider_id'] ||
        ! $item['post_created_at'] || ! $item['public_metrics']) {
        throw new RuntimeException('Audited post identity or metrics changed for '.$row->id);
    }
    $counts[$item['classification']]++;
    if (($item['classification'] === 'owned') !== ($item['author_id'] === $item['account_provider_id'])) {
        throw new RuntimeException('Author classification mismatch for '.$row->id);
    }
}
if ($counts !== ['owned' => 3, 'foreign' => 33, 'unresolved' => 0]) {
    throw new RuntimeException('The no-metrics author breakdown changed.');
}

$backupPath = '/tmp/x-unclassified-inbox-preimage-backup.json';
file_put_contents($backupPath, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
chmod($backupPath, 0600);
if (! in_array('--apply', $argv, true)) {
    echo json_encode(['status' => 'validated', 'rows' => $rows->count(), 'backup' => $backupPath]).PHP_EOL;
    exit;
}

$now = Carbon::now('UTC');
$metricTypes = [
    1 => 'impression_count', 2 => 'like_count', 3 => 'retweet_count',
    4 => 'reply_count', 5 => 'quote_count', 6 => 'bookmark_count',
];

DB::transaction(function () use ($rows, $classified, $metricTypes, $now) {
    $insights = [];
    $history = [];
    foreach ($rows as $row) {
        $item = $classified[$row->id];
        $owned = $item['classification'] === 'owned';
        $updated = DB::table('mixpost_imported_posts')->where('id', $row->id)
            ->whereNull('data')->where('created_at', $row->created_at)
            ->update([
                'data' => json_encode([
                    'source' => 'inbox_engagement',
                    'author_id' => $item['author_id'],
                    'analytics_visible' => $owned,
                ], JSON_THROW_ON_ERROR),
                'created_at' => Carbon::parse($item['post_created_at'], 'UTC')->format('Y-m-d H:i:s'),
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('Concurrent update of imported post '.$row->id);
        }
        if (! $owned) {
            continue;
        }
        foreach ($metricTypes as $type => $field) {
            if (! array_key_exists($field, (array) $item['public_metrics'])) {
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
        $insights, ['workspace_id', 'account_id', 'provider_post_id', 'type'], ['value', 'updated_at']
    );
    DB::table('mixpost_twitter_post_insight_history')->upsert(
        $history, ['workspace_id', 'account_id', 'provider_post_id', 'type', 'date'], ['value']
    );
});

echo json_encode(['status' => 'applied', 'owned' => 3, 'foreign' => 33], JSON_THROW_ON_ERROR).PHP_EOL;
