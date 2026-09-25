<?php

declare(strict_types=1);

require getenv('MIXPOST_VENDOR_AUTOLOAD') ?: dirname(__DIR__, 3).'/vendor/autoload.php';
require dirname(__DIR__).'/YoutubeAudienceReport.php';
require dirname(__DIR__).'/YoutubeAudienceSnapshot.php';
require dirname(__DIR__).'/YoutubeAudienceCollector.php';

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Inovector\Mixpost\Support\YoutubeAudienceReport as Report;
use Inovector\Mixpost\Support\YoutubeAudienceSnapshot as Snapshot;
use Inovector\Mixpost\Support\YoutubeAudienceCollector as Collector;

$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (! $condition) { throw new RuntimeException($message); }
    $checks++;
}
$container = new Container;
$capsule = new Capsule($container);
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
$capsule->setAsGlobal(); $capsule->bootEloquent();
$container->instance('db', $capsule->getDatabaseManager());
$container->bind('db.schema', fn () => $capsule->schema());
Facade::setFacadeApplication($container);
$capsule->schema()->create('mixpost_workspaces', fn (Blueprint $table) => $table->id());
$capsule->schema()->create('mixpost_accounts', function (Blueprint $table): void { $table->id(); $table->unsignedBigInteger('workspace_id'); $table->string('provider'); $table->string('provider_id'); });
DB::table('mixpost_workspaces')->insert([['id' => 25], ['id' => 26]]);
DB::table('mixpost_accounts')->insert(['id' => 97, 'workspace_id' => 25, 'provider' => 'youtube', 'provider_id' => 'UCG35kFqmv3Ka8MbXZqTKAMw']);
$migration = require dirname(__DIR__).'/2026_09_25_210000_create_youtube_audience_reports.php';
$migration->up();
check($capsule->schema()->hasColumns(Snapshot::TABLE, ['report_json', 'fetched_at', 'last_attempt_at', 'last_attempt_status', 'last_attempt_error']), 'Migration installs contract');
$report = Report::collect(97, 25, 'UCG35kFqmv3Ka8MbXZqTKAMw', '2026-09-01', '2026-09-25', Report::SCOPE, static function ($url, $query): array {
    return ['status' => 200, 'body' => ['columnHeaders' => [['name' => $query['dimensions']], ['name' => $query['metrics']]], 'rows' => match ($query['dimensions']) { 'ageGroup' => [], 'gender' => [['male', 100]], 'country' => [['US', 46]] }]];
});
$report['fetchedAt'] = '2026-09-25T19:00:00Z';
check($report['status'] === 'partial' && Snapshot::successful($report), 'Mixed privacy empty plus data counts as successful fetch');
Snapshot::store($report);
$row = (array) DB::table(Snapshot::TABLE)->first();
check($row['last_attempt_error'] === null, 'Privacy limited is not a transport failure');
check(json_decode($row['report_json'], true)['reports']['country']['buckets'][0]['value'] === 46, 'Country data persisted');
$failed = $report;
$failed['fetchedAt'] = '2026-09-25T20:00:00Z';
$failed['reports']['gender'] = ['status' => 'unavailable', 'reason' => 'transport_error', 'buckets' => []];
Snapshot::store($failed);
$row = (array) DB::table(Snapshot::TABLE)->first();
check($row['report_json'] === json_encode($report), 'Failure retains entire previous good range');
check($row['fetched_at'] === '2026-09-25 19:00:00.000000' && $row['last_attempt_at'] === '2026-09-25 20:00:00.000000', 'Freshness remains old while attempt advances');
check($row['last_attempt_error'] === 'transport_error', 'Attempt records safe failure reason');
$empty = $report;
$empty['fetchedAt'] = '2026-09-25T21:00:00Z';
$empty['status'] = 'no_reportable_data';
foreach ($empty['reports'] as &$dimension) { $dimension['status'] = 'no_reportable_data'; $dimension['buckets'] = []; unset($dimension['reportedTotal']); } unset($dimension);
Snapshot::store($empty);
$row = (array) DB::table(Snapshot::TABLE)->first();
check(json_decode($row['report_json'], true)['reports']['country']['buckets'] === [], 'Successful empty replaces obsolete demographics');
check($row['last_attempt_error'] === null, 'Successful empty clears failure');
Snapshot::store($failed);
check(((array) DB::table(Snapshot::TABLE)->first())['last_attempt_at'] === '2026-09-25 21:00:00.000000', 'Late older attempt cannot overwrite newer result');
$failed['period']['start'] = '2026-09-24';
Snapshot::store($failed);
check(DB::table(Snapshot::TABLE)->count() === 2, 'Different exact ranges persist independently');
check(json_decode(DB::table(Snapshot::TABLE)->where('start_date','2026-09-24')->value('report_json'), true)['reports']['gender']['status'] === 'unavailable', 'First failure is explicit, never borrowed from another range');
$wrong = $report; $wrong['workspaceId'] = 26;
try { Snapshot::store($wrong); throw new RuntimeException('Cross-workspace snapshot accepted'); } catch (InvalidArgumentException) { $checks++; }
$wrong = $report; $wrong['channelId'] = 'UCaaaaaaaaaaaaaaaaaaaaaa';
try { Snapshot::store($wrong); throw new RuntimeException('Wrong channel accepted'); } catch (InvalidArgumentException) { $checks++; }
check(Collector::workspaceIds('') === [] && Collector::workspaceIds('*') === [] && Collector::workspaceIds('25,') === [], 'Invalid/missing opt-in fails closed');
check(Collector::workspaceIds('25,26,25') === [25,26], 'Explicit unique workspaces');
$windows = Snapshot::windows(new DateTimeImmutable('2026-09-26T02:00:00Z'));
check($windows[0] === ['start' => '2026-09-25', 'end' => '2026-09-25'], 'Pacific date, not UTC date');
check($windows[1]['start'] === '2026-09-21' && $windows[2]['start'] === '2026-09-01', 'Calendar week/month');
check(count($windows) === 6 && $windows[5]['start'] === '2026-09-22', 'Revisit recent closed days for lag');
check(count(Snapshot::windows(new DateTimeImmutable('2026-06-01T12:00:00Z'))) === 4, 'First Monday deduplicates day/week/month');
DB::table('mixpost_accounts')->where('id',97)->delete();
check(DB::table(Snapshot::TABLE)->count() === 0, 'Deleting account cascades audience data');
$migration->down();
check(! $capsule->schema()->hasTable(Snapshot::TABLE), 'Migration rolls back cleanly');
echo "YouTube snapshot persistence: {$checks} checks passed\n";
