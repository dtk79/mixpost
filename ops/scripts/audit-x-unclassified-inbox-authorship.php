<?php

// Read-only production audit for X posts imported for Inbox conversations.
// Run from the Mixpost container after copying this file to /tmp.

require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Inovector\Mixpost\Facades\SocialProviderManager;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Models\Account;

$rows = DB::table('mixpost_imported_posts as p')
    ->join('mixpost_accounts as a', 'a.id', '=', 'p.account_id')
    ->join('mixpost_inbox_conversations as c', 'c.imported_post_id', '=', 'p.id')
    ->where('a.provider', 'twitter')
    ->whereNull('p.data')
    ->select('p.id', 'p.account_id', 'p.provider_post_id', 'p.created_at', 'a.provider_id')
    ->distinct()
    ->orderBy('p.id')
    ->get();

$account = Account::withoutWorkspace()->findOrFail(16);
WorkspaceManager::setCurrent($account->workspace);
$provider = SocialProviderManager::connect($account->provider, $account->values())
    ->useAccessToken($account->access_token->toArray());

$authors = [];
$postDates = [];
$publicMetrics = [];
$errors = [];
$ids = $rows->pluck('provider_post_id')->unique()->values()->all();

foreach (array_chunk($ids, 100) as $chunk) {
    $response = $provider->connection->get('tweets', [
        'ids' => implode(',', $chunk),
        'tweet.fields' => 'author_id,created_at,public_metrics',
    ]);
    $httpCode = $provider->connection->getLastHttpCode();
    if ($httpCode !== 200) {
        throw new RuntimeException('X post lookup failed with HTTP '.$httpCode.'; no rows classified.');
    }

    foreach ($response->data ?? [] as $post) {
        if (! isset($post->id, $post->author_id)) {
            continue;
        }
        $authors[(string) $post->id] = (string) $post->author_id;
        $postDates[(string) $post->id] = $post->created_at ?? null;
        $publicMetrics[(string) $post->id] = $post->public_metrics ?? null;
    }
    foreach ($response->errors ?? [] as $error) {
        $errors[] = ['id' => (string) ($error->value ?? ''), 'title' => (string) ($error->title ?? '')];
    }
}

$result = ['owned' => [], 'foreign' => [], 'unresolved' => [], 'errors' => $errors];
foreach ($rows as $row) {
    $id = (string) $row->provider_post_id;
    $authorId = $authors[$id] ?? null;
    $item = [
        'imported_post_id' => (int) $row->id,
        'account_id' => (int) $row->account_id,
        'provider_post_id' => $id,
        'account_provider_id' => (string) $row->provider_id,
        'author_id' => $authorId,
        'post_created_at' => $postDates[$id] ?? null,
        'public_metrics' => $publicMetrics[$id] ?? null,
    ];
    $kind = $authorId === null ? 'unresolved' : ($authorId === (string) $row->provider_id ? 'owned' : 'foreign');
    $result[$kind][] = $item;
}

file_put_contents('/tmp/x-unclassified-inbox-authorship.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode([
    'cohort' => $rows->count(),
    'unique_posts' => count($ids),
    'owned' => count($result['owned']),
    'foreign' => count($result['foreign']),
    'unresolved' => count($result['unresolved']),
    'api_errors' => count($errors),
    'sample_foreign' => array_slice($result['foreign'], 0, 2),
    'sample_unresolved' => array_slice($result['unresolved'], 0, 3),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
