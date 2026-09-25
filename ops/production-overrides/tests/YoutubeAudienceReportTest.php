<?php

declare(strict_types=1);
require dirname(__DIR__).'/YoutubeAudienceReport.php';
use Inovector\Mixpost\Support\YoutubeAudienceReport as Report;

$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (! $condition) { throw new RuntimeException($message); }
    $checks++;
}
function collect(callable $get, string $scope = Report::SCOPE, string $subscription = 'all'): array {
    return Report::collect(97, 25, 'UCG35kFqmv3Ka8MbXZqTKAMw', '2026-09-01', '2026-09-25', $scope, $get, $subscription);
}
function response(string $dimension, string $metric, array $rows): array {
    // Deliberately reorder the provider headers to exercise named-column parsing.
    return ['status' => 200, 'body' => ['columnHeaders' => [['name' => $metric], ['name' => $dimension]], 'rows' => $rows]];
}
$calls = [];
$fixture = static function ($url, $query) use (&$calls) {
    $calls[] = [$url, $query];
    return match ($query['dimensions']) {
        'ageGroup' => response('ageGroup', 'viewerPercentage', [[10.0, 'age13-17'], [65.0, 'age25-34'], [24.5, 'age65-']]),
        'gender' => response('gender', 'viewerPercentage', [[74.0, 'male'], [21.0, 'female'], [5.0, 'user_specified']]),
        'country' => response('country', 'views', [[90, 'US'], [10, 'ZZ']]),
    };
};
$r = collect($fixture);
check($r['status'] === 'available', 'All reports available');
check(count($calls) === 3 && $calls[0][0] === 'https://youtubeanalytics.googleapis.com/v2/reports', 'Three bounded requests');
check($calls[0][1]['ids'] === 'channel==UCG35kFqmv3Ka8MbXZqTKAMw', 'Explicit channel identity, never MINE');
check(! isset($calls[0][1]['filters']), 'All viewers not subscribed only');
check($r['reports']['age']['reportedTotal'] === 99.5, 'Do not rebase withheld percentages to 100');
check($r['reports']['age']['buckets'][0]['key'] === 'age13-17', 'Retain minor bucket in contract for truthful denominator');
check($r['reports']['country']['population'] === 'video_views' && $r['reports']['country']['reportedTotal'] === 100, 'Country is views, not people');
check($r['period']['timeZone'] === 'America/Los_Angeles' && $r['dataThrough'] === null, 'No fabricated completeness date');
check(! isset($r['reports']['age']['count']), 'No synthesized demographic counts');
$calls = [];
$r = collect($fixture, 'https://www.googleapis.com/auth/youtube https://www.googleapis.com/auth/youtube.upload');
check($r['status'] === 'authorization_required' && count($calls) === 0, 'Existing publishing scopes cannot read analytics');
collect($fixture, Report::SCOPE, 'subscribed');
check($calls[0][1]['filters'] === 'subscribedStatus==SUBSCRIBED', 'Subscribed viewers isolated by filter, not summed');
$r = collect(static fn ($u, $q) => response($q['dimensions'], $q['metrics'], []));
check($r['status'] === 'no_reportable_data' && $r['reports']['age']['buckets'] === [], 'Empty data not zero demographic population');
$r = collect(static fn () => ['status' => 403, 'body' => ['error' => ['details' => [['reason' => 'ACCESS_TOKEN_SCOPE_INSUFFICIENT']]]]]);
check($r['status'] === 'authorization_required', 'Scope refusal has reconnection state');
$r = collect(static fn () => ['status' => 403, 'body' => ['error' => ['details' => [['reason' => 'SERVICE_DISABLED']]]]]);
check($r['reports']['country']['reason'] === 'api_not_enabled', 'Disabled API distinct from no data');
$r = collect(static fn () => throw new RuntimeException('secret credential detail'));
check($r['status'] === 'unavailable' && ! str_contains(json_encode($r), 'secret'), 'Transport errors sanitized');
$r = collect(static fn ($u, $q) => $q['dimensions'] === 'country' ? response('country', 'views', [[4, 'GB']]) : response($q['dimensions'], $q['metrics'], []));
check($r['status'] === 'partial' && $r['reports']['country']['status'] === 'available', 'Country can be present while demographics suppressed');
foreach ([null, 'bad', ['status' => 200, 'body' => []], ['status' => 200, 'body' => ['columnHeaders' => [['name' => 'wrong']]]]] as $bad) {
    $r = collect(static fn () => $bad);
    check($r['status'] === 'unavailable', 'Reject malformed provider payload');
}
foreach ([[[101, 'male']], [[-1, 'male']], [[50, 'male'], [60, 'female']], [[10, 'male'], [10, 'male']], [['not a number', 'male']]] as $badRows) {
    $r = collect(static fn ($u, $q) => response($q['dimensions'], $q['metrics'], $badRows));
    check($r['reports']['gender']['status'] === 'unavailable', 'Reject invalid percentage data');
}
foreach ([['2026-02-30', '2026-03-01'], ['2026-09-25', '2026-09-01'], ['bad', 'bad']] as [$start, $end]) {
    try { Report::validateRange($start, $end); throw new RuntimeException('Accepted invalid date'); }
    catch (InvalidArgumentException) { $checks++; }
}
try { collect($fixture, Report::SCOPE, 'followers'); throw new RuntimeException('Accepted invalid audience type'); }
catch (InvalidArgumentException) { $checks++; }
$manifest = json_decode(file_get_contents(dirname(__DIR__).'/deployment-manifest.json'), true);
foreach (['YoutubeProvider.php', 'YoutubeAudienceReport.php'] as $file) {
    check(count(array_filter($manifest['overrides'], fn ($entry) => $entry['host'] === $file)) === 1, 'Managed override present once: '.$file);
}
check(str_contains(file_get_contents(dirname(__DIR__).'/YoutubeProvider.php'), "'".Report::SCOPE."'"), 'OAuth requests analytics read scope');
echo "YouTube audience: {$checks} checks passed\n";
