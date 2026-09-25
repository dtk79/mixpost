<?php
// Uses installed Pro dependencies with in-memory query results and faked queues/HTTP.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e) { fwrite(STDERR, (string)$e); exit(1); });
require __DIR__.'/../AccountPublishPost.php';
require __DIR__.'/../AccountPublishPostJob.php';
require __DIR__.'/../OptimizeSocialVideoMediaJob.php';

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\Actions\Post\AccountPublishPost;
use Inovector\Mixpost\Jobs\OptimizeSocialVideoMediaJob;
use Inovector\Mixpost\Models\{Account, Post, PostVersion, Workspace};
use Inovector\Mixpost\Facades\WorkspaceManager;

config(['cache.default'=>'array']);
Illuminate\Support\Facades\Cache::purge();
$app->instance('cache.store', Illuminate\Support\Facades\Cache::store('array'));
Http::preventStrayRequests();
Bus::fake();
// Exercise real Eloquent parsing/relations without connecting to the production DB.
$connection = new class(null) extends Illuminate\Database\MySqlConnection {
    public function getServerVersion(): string { return '8.0.0'; }
    public function isMaria(): bool { return false; }
    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []) {
        if (str_contains($query, '`mixpost_media`')) {
            $rows = [];
            foreach ([738,714] as $id) {
                if (!in_array($id, $bindings)) continue;
                $rows[] = ['id'=>$id, 'workspace_id'=>24, 'mime_type'=>'video/mp4', 'processing'=>false,
                    'conversions'=>json_encode($id===714 ? [['name'=>'social_video','disk'=>'s3','path'=>'fixture.mp4','size'=>100]] : [])];
            }
            return $rows;
        }
        if (str_contains($query, '`mixpost_accounts`')) {
            $published = in_array(1885,$bindings);
            return [['id'=>85,'provider'=>'instagram','workspace_id'=>24,'pivot_account_id'=>85,
                'pivot_post_id'=>$published ? 1885 : 1886,'pivot_provider_post_id'=>$published ? 'existing-id' : null]];
        }
        throw new RuntimeException('Unexpected database query in isolated test: '.$query);
    }
};
$resolver=new Illuminate\Database\ConnectionResolver(['fixture'=>$connection]);
$resolver->setDefaultConnection('fixture');
Illuminate\Database\Eloquent\Model::setConnectionResolver($resolver);
WorkspaceManager::setCurrent((new Workspace)->forceFill(['id'=>24]));
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function postFixture(array $items, ?array $accountItems = null): Post {
    $p = new Post;
    $original = new PostVersion(['account_id'=>0, 'is_original'=>true, 'content'=>$items, 'options'=>[]]);
    $versions = collect([$original]);
    if ($accountItems !== null) $versions->push(new PostVersion(['account_id'=>85, 'is_original'=>false, 'content'=>$accountItems, 'options'=>[]]));
    $p->setRelation('versions', $versions);
    return $p;
}
$action = new AccountPublishPost;
$a = new Account(['provider'=>'instagram']); $a->id=85;
$item = ['body'=>'test', 'media'=>['738'], 'url'=>null];
check(!$action->prepareSocialVideos($a, postFixture([$item])), 'Historical video must wait');
check(count(Bus::dispatched(OptimizeSocialVideoMediaJob::class)) === 1, 'Historical video must queue conversion');
(new Illuminate\Bus\UniqueLock(app(Illuminate\Contracts\Cache\Repository::class)))->release(new OptimizeSocialVideoMediaJob(738));
Bus::fake();
check(!$action->prepareSocialVideos($a, postFixture([$item, $item])), 'Additional items must wait too');
check(count(Bus::dispatched(OptimizeSocialVideoMediaJob::class)) === 1, 'Repeated attachment queues once: '.count(Bus::dispatched(OptimizeSocialVideoMediaJob::class)));
(new Illuminate\Bus\UniqueLock(app(Illuminate\Contracts\Cache\Repository::class)))->release(new OptimizeSocialVideoMediaJob(738));
Bus::fake();
check($action->prepareSocialVideos($a, postFixture([$item], [['body'=>'text only', 'media'=>[]]])), 'Account-specific content takes precedence');
check(count(Bus::dispatched(OptimizeSocialVideoMediaJob::class)) === 0, 'Unused original must not queue');
check($action->prepareSocialVideos($a, postFixture([['body'=>'ready', 'media'=>['714']]])), 'Prepared video is ready');
foreach (['twitter','threads','bluesky','instagram_standalone'] as $provider) {
    $a->provider=$provider;
    check(!$action->prepareSocialVideos($a, postFixture([$item])), $provider.' must prepare historical videos');
}
$a->provider='youtube';
check($action->prepareSocialVideos($a, postFixture([$item])), 'YouTube retains its original video');
$a->provider='instagram';
$response=$action($a, postFixture([$item]));
check($response->hasError() && $response->context() === ['social_video_optimization_pending'], 'Direct action must stop before provider HTTP');
$j=new OptimizeSocialVideoMediaJob(738);
check($j instanceof Illuminate\Contracts\Queue\ShouldBeUnique, 'Conversion dispatch must be unique across providers');
check($j->uniqueId()==='738' && count($j->middleware())===1, 'Conversion lock must be scoped to media');
$job=new Inovector\Mixpost\Jobs\AccountPublishPostJob($a,postFixture([]));
check($job->tries===10 && $job->maxExceptions===1, 'Wait allowance must retain bounded exception retries');
echo "PASS: historical media, account versions, multiple attachments, prepared video, five providers, direct guard, uniqueness, bounded retries\n";

// Exercise the real worker's wait, timeout, and already-published branches.
class ReadOnlyPost extends Post {
    public array $capturedErrors=[];
    public function isInHistory(): bool { return false; }
    public function hasProcessingMedia(): bool { return false; }
    public function insertErrors(Account $account, $errors): void { $this->capturedErrors=$errors; }
}
class ReadOnlyAccount extends Account {
    public function isServiceActive(): bool { return true; }
    public function isUnauthorized(): bool { return false; }
}
class WorkerFixture extends Inovector\Mixpost\Jobs\AccountPublishPostJob {
    public ?int $releasedFor=null;
    public function batch() { return new class { public function cancelled() { return false; } }; }
    public function release($delay=0) { $this->releasedFor=$delay; }
    public function rateLimitExpiration() { return null; }
}
class PendingAction extends AccountPublishPost {
    public int $publishCalls=0;
    public bool $ready=false;
    public function prepareSocialVideos(Account $account, Post $post): bool { return $this->ready; }
    public function __invoke(Account $account, Post $post): Inovector\Mixpost\Support\SocialProviderResponse {
        $this->publishCalls++;
        return new Inovector\Mixpost\Support\SocialProviderResponse(Inovector\Mixpost\Enums\SocialProviderResponseStatus::OK, ['id'=>'fake']);
    }
}
$a=new ReadOnlyAccount; $a->id=85; $a->provider='instagram';
$p=new ReadOnlyPost; $p->id=1886;
$action=new PendingAction;
$worker=new WorkerFixture($a,$p);$worker->handle($action);
check($worker->releasedFor===30 && $action->publishCalls===0 && !$p->capturedErrors, 'Pending media must wait without failing or calling provider');
Illuminate\Support\Facades\Cache::put('social-video-wait:1886:85', time()-1801,7200);
$worker=new WorkerFixture($a,$p);$worker->handle($action);
check($worker->releasedFor===null && count($p->capturedErrors)===1 && $action->publishCalls===0, 'Preparation timeout must record failure without uploading original');
$p->capturedErrors=[];$action->ready=true;
$worker=new WorkerFixture($a,$p);$worker->handle($action);
check($worker->releasedFor===null && $action->publishCalls===1, 'Ready media resumes publishing');
$p->id=1885;$action->publishCalls=0;
$worker=new WorkerFixture($a,$p);$worker->handle($action);
check($action->publishCalls===0, 'Already-published account must not resubmit');
echo "PASS: worker waits, times out safely, resumes when ready, skips published destination\n";

// Recreate only confirmed terminal Instagram uploads, at most twice.
Illuminate\Support\Facades\Log::swap(new Psr\Log\NullLogger);
class RetryWorkerFixture extends WorkerFixture {
    public bool $deleted=false;
    public function delete() { $this->deleted=true; }
}
class ResultAction extends PendingAction {
    public Inovector\Mixpost\Support\SocialProviderResponse $result;
    public function __invoke(Account $account, Post $post): Inovector\Mixpost\Support\SocialProviderResponse { $this->publishCalls++; return $this->result; }
}
$p->id=1886;$a->provider='instagram';
$action=new ResultAction;$action->ready=true;
$context=['errors'=>['Error: Media upload has failed with error code 2207082'], 'phase'=>'instagram_container_processing','container_status'=>'ERROR','container_id'=>'failed-container'];
$action->result=new Inovector\Mixpost\Support\SocialProviderResponse(Inovector\Mixpost\Enums\SocialProviderResponseStatus::ERROR,$context);
foreach([60,120,null] as $delay) {
    $worker=new RetryWorkerFixture($a,$p);$worker->handle($action);
    check($worker->releasedFor===$delay && $worker->deleted===($delay===null),'Two delayed retries then terminal failure');
}
Illuminate\Support\Facades\Cache::forget('instagram-upload-retry:1886:85');
foreach([
    ['errors'=>['2207082']],
    array_merge($context,['phase'=>'media_publish']),
    array_merge($context,['container_status'=>'IN_PROGRESS']),
    array_merge($context,['errors'=>['2207077']]),
] as $unsafe) {
    $action->result=new Inovector\Mixpost\Support\SocialProviderResponse(Inovector\Mixpost\Enums\SocialProviderResponseStatus::ERROR,$unsafe);
    $worker=new RetryWorkerFixture($a,$p);$worker->handle($action);
    check($worker->releasedFor===null && $worker->deleted,'Uncertain/permanent/publish failures must not retry');
}
$action->result=new Inovector\Mixpost\Support\SocialProviderResponse(Inovector\Mixpost\Enums\SocialProviderResponseStatus::OK,['id'=>'recovered']);
Illuminate\Support\Facades\Cache::put('instagram-upload-retry:1886:85',1,86400);
$worker=new RetryWorkerFixture($a,$p);$worker->handle($action);
check(!Illuminate\Support\Facades\Cache::has('instagram-upload-retry:1886:85'),'Success clears retry counter');
$p->id=1885;$action->publishCalls=0;
$worker=new RetryWorkerFixture($a,$p);$worker->handle($action);
check($action->publishCalls===0,'Retries still skip successful destinations');
echo "PASS: bounded upload retries, ambiguous/permanent errors excluded, success resets, duplicate guard retained\n";
