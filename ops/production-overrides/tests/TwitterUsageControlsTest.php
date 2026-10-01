<?php
// Run only in the isolated Pro rehearsal; no provider calls or persistent writes.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e): void { fwrite(STDERR, (string) $e); exit(1); });
use Carbon\Carbon;
use Illuminate\Support\Facades\{DB, Http, Bus, Event, Notification};
use Inovector\Mixpost\Models\{Account, Workspace};
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\SocialProviders\Twitter\TwitterProvider;
use Inovector\Mixpost\SocialProviders\Twitter\Enums\TwitterAnalyticsRange as Range;
use Inovector\Mixpost\SocialProviders\Twitter\Jobs\ImportTwitterPostsJob;
use Inovector\Mixpost\Support\SocialProviderResponse;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Contracts\SocialProvider;
use Illuminate\Contracts\Queue\ShouldQueue;
Http::preventStrayRequests(); Bus::fake(); Event::fake(); Notification::fake();
class UsageProviderFixture extends TwitterProvider {
    public static Range $range = Range::FULL;
    public array $calls = [];
    public static function analyticsRange(): Range { return self::$range; }
    public static function getTier(): string { return 'pay_as_you_go'; }
    public function getUserTweetTimeline(string $userId, string $paginationToken = '', bool $withNonPublicMetrics = true, ?string $startTime = null, ?string $endTime = null): SocialProviderResponse {
        $this->calls[] = [$paginationToken, $withNonPublicMetrics, $startTime, $endTime];
        return new SocialProviderResponse(SocialProviderResponseStatus::OK, []);
    }
}
class UsageJobFixture extends ImportTwitterPostsJob {
    public UsageProviderFixture $provider;
    public ?ShouldQueue $next = null;
    public bool $initial = false;
    public function batch(): ?Illuminate\Bus\Batch {
        return $this->initial ? (new ReflectionClass(Illuminate\Bus\Batch::class))->newInstanceWithoutConstructor() : null;
    }
    public function connectProvider(Account $account): SocialProvider { return $this->provider; }
    public function invoke(): SocialProviderResponse { return $this->execute(); }
    public function page(SocialProviderResponse $response): void { $this->processResponse($response); }
    protected function dispatchOrAddToBatch(ShouldQueue $job): void { $this->next = $job; }
}
function usageCheck(bool $ok, string $message): void {
    if (! $ok) { throw new RuntimeException($message); }
    echo "PASS $message\n";
}
$account = Account::withoutGlobalScopes()->where('provider', 'twitter')->firstOrFail();
WorkspaceManager::setCurrent(Workspace::findOrFail($account->workspace_id));
$provider = new UsageProviderFixture(Illuminate\Http\Request::create('/'), '', '', '', []);
$make = function(array $options = []) use ($account, $provider): UsageJobFixture {
    $provider->calls = [];
    $job = new UsageJobFixture($account, $options); $job->provider = $provider; return $job;
};
Carbon::setTestNow(Carbon::parse('2026-10-01T12:00:00Z'));
DB::beginTransaction();
try {
    UsageProviderFixture::$range = Range::DISABLED;
    usageCheck($make()->invoke()->isOk() && $provider->calls === [], 'disabled analytics makes no X request');
    usageCheck(UsageProviderFixture::initialJobs() === [] && UsageProviderFixture::highPriorityJobs() === [] && UsageProviderFixture::dailyPriorityJobs() === [], 'disabled analytics also stops initial, follower and daily jobs');
    UsageProviderFixture::$range = Range::LAST_7_DAYS;
    $job = $make(['timeline_start_time' => '2026-08-01T00:00:00Z']); $job->invoke();
    usageCheck($provider->calls[0][2] === '2026-09-24T12:00:00Z', '7-day limit clamps older scheduled windows');
    $make(['timeline_end_time' => '2026-09-20T00:00:00Z'])->invoke();
    usageCheck($provider->calls === [], 'historical window outside allowed range makes no X request');
    UsageProviderFixture::$range = Range::LAST_30_DAYS;
    $make(['start_time' => '2026-01-01T00:00:00Z'])->invoke();
    usageCheck($provider->calls[0][2] === '2026-09-01T12:00:00Z', '30-day limit also clamps vendor pagination options');
    $make(['timeline_start_time' => '2026-09-28T00:00:00Z'])->invoke();
    usageCheck($provider->calls[0][2] === '2026-09-28T00:00:00Z', 'narrower explicit window is retained');
    UsageProviderFixture::$range = Range::FULL;
    $job = $make(['timeline_start_time' => null, 'timeline_end_time' => '2026-07-01T00:00:00Z']); $job->invoke();
    usageCheck($provider->calls[0][2] === null && $provider->calls[0][3] === '2026-07-01T00:00:00Z', 'full analytics retains older-than-90-day custom cadence');
    $make()->invoke();
    usageCheck($provider->calls[0][2] === '2026-09-01T12:00:00Z', 'unwindowed daily job uses vendor 30-day default');
    $initial = $make(); $initial->initial = true; $initial->invoke();
    usageCheck($provider->calls[0][2] === null, 'full initial import retains vendor unlimited history');
    UsageProviderFixture::$range = Range::LAST_7_DAYS;
    $initial = $make(); $initial->initial = true; $initial->invoke();
    usageCheck($provider->calls[0][2] === '2026-09-24T12:00:00Z', 'limited initial import respects selected range');
    UsageProviderFixture::$range = Range::FULL;
    $job = $make(['timeline_start_time' => '2026-08-01T00:00:00Z', 'timeline_end_time' => '2026-09-01T00:00:00Z']); $job->invoke();
    $job->page(new SocialProviderResponse(SocialProviderResponseStatus::OK, [
        'data' => [(object) ['id'=>'usage-control-fixture', 'text'=>'fixture', 'created_at'=>'2026-08-20T00:00:00Z']],
        'meta' => (object) ['next_token'=>'fixture-next'],
    ]));
    usageCheck($job->next !== null && $job->next->options['timeline_start_time'] === '2026-08-01T00:00:00Z' && $job->next->options['timeline_end_time'] === '2026-09-01T00:00:00Z' && $job->next->options['public_metrics_only'] === true, 'pagination retains both custom bounds and historical public-metrics fallback');
    $job = $make(['timeline_start_time'=>'2026-09-28T00:00:00Z']);
    UsageProviderFixture::$range = Range::DISABLED; $job->invoke();
    usageCheck($provider->calls === [], 'in-flight pagination respects newly disabled analytics');
} finally { DB::rollBack(); Carbon::setTestNow(); }
echo "X usage-control fixtures rolled back; no external requests.\n";
