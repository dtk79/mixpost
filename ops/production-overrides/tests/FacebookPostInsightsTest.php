<?php

// Installed licensed candidate, fake provider HTTP only. No imports or database writes.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): void { fwrite(STDERR, (string) $error); exit(1); });

use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\SocialProviders\Meta\FacebookPageInsights\FacebookPageFetchPosts;
use Inovector\Mixpost\SocialProviders\Meta\FacebookPageInsights\FacebookPageFetchPostInsights;

function facebookCheck(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
    echo "PASS $message\n";
}

$requests = [];
$deny = false;
Http::preventStrayRequests();
Http::fake(function ($request) use (&$requests, &$deny) {
    $requests[] = $request;
    if ($deny) { return Http::response(['error' => ['message' => 'permission denied', 'code' => 190]], 400); }
    if (str_contains($request['fields'] ?? $request['metric'] ?? '', 'post_video_views_unique')) {
        return Http::response(['error' => ['message' => '(#100) The value must be a valid insights metric', 'code' => 100]], 400);
    }
    if (isset($request['fields'])) {
        return Http::response(['data' => [[
            'id' => 'page_post', 'created_time' => '2026-10-17T13:00:00+0000',
            'insights' => ['data' => [['name' => 'post_media_view', 'values' => [['value' => 257]]]]],
        ]], 'paging' => ['cursors' => ['after' => 'next-page']]]);
    }
    return Http::response(['data' => [
        ['name' => 'post_video_views', 'values' => [['value' => 96, 'end_time' => '2026-10-17T00:00:00+0000']]],
        ['name' => 'post_video_views_paid', 'values' => [['value' => 0, 'end_time' => '2026-10-17T00:00:00+0000']]],
    ]]);
});

$posts = new FacebookPageFetchPosts('fixture-token', 'https://graph.test', 'v25.0', ['provider_id' => 'fixture-page']);
$result = $posts->handle();
facebookCheck($result->isOk() && $result->data[0]['id'] === 'page_post', 'post discovery succeeds when Meta rejects deprecated unique video views');
facebookCheck($result->data[0]['insights']['data'][0]['values'][0]['value'] === 257, 'supported post media views retain their provider value');
$fields = $requests[0]['fields'];
foreach (['post_media_view', 'post_reactions_like_total', 'post_clicks', 'post_video_views', 'post_video_avg_time_watched', 'comments.summary', 'shares'] as $field) {
    facebookCheck(str_contains($fields, $field), 'post request preserves '.$field);
}
facebookCheck(! str_contains($fields, 'post_video_views_unique'), 'post request excludes rejected metric');
$posts->handle(['after' => 'next-page', 'limit' => 5]);
facebookCheck($requests[1]['after'] === 'next-page' && $requests[1]['limit'] === 5, 'pagination and reduced-limit retry remain intact');

$video = new FacebookPageFetchPostInsights('fixture-token', 'https://graph.test', 'v25.0');
$result = $video->handle('fixture-post');
facebookCheck($result->isOk() && $result->context()['data'][0]['values'][0]['value'] === 96, 'per-video daily insights succeed with supported metrics');
facebookCheck($result->context()['data'][1]['values'][0]['value'] === 0, 'provider zero values remain zero');
$expected = ['post_video_views_organic', 'post_video_views_paid', 'post_video_views', 'post_video_views_60s_excludes_shorter', 'post_video_social_actions_count_unique', 'post_video_view_time', 'post_video_view_time_organic'];
facebookCheck(explode(',', $requests[2]['metric']) === $expected && $requests[2]['period'] === 'day', 'all seven supported daily video metrics are preserved');
facebookCheck(str_contains($requests[2]->url(), '/fixture-post/insights'), 'video request retains its provider post identity');
$deny = true;
facebookCheck($posts->handle()->hasError() && $video->handle('fixture-post')->hasError(), 'genuine provider errors remain visible');
echo "Facebook post-insights compatibility passed; no database writes or external requests.\n";
