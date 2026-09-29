<?php
require '/var/www/html/vendor/autoload.php';
$app=require '/var/www/html/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(Throwable $e){fwrite(STDERR,(string)$e);exit(1);});
use Illuminate\Support\Facades\{DB,Event,Http,Bus,Notification};
use Illuminate\Support\Collection;
use Inovector\Mixpost\Models\{Post,Account,Workspace};
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Support\SocialProviderResponse;
use Inovector\Mixpost\Enums\{SocialProviderResponseStatus as Result,PostAccountStatus};
use Inovector\Mixpost\Actions\Post\AccountPublishPost;
use Inovector\Mixpost\Contracts\SocialProvider;
Event::fake();Bus::fake();Notification::fake();Http::preventStrayRequests();
class V7ProviderFixture extends Inovector\Mixpost\SocialProviders\Twitter\TwitterProvider {
    public array $calls=[];
    public array $responses=[];
    public function publishPost(string $text, Collection $media, array $params=[]): SocialProviderResponse {
        $this->calls[]=[$text,$params];return array_shift($this->responses);
    }
}
class V7ActionFixture extends AccountPublishPost {
    public bool $ready=true;
    public function __construct(public V7ProviderFixture $provider) {}
    public function connectProvider(Account $account): SocialProvider {return $this->provider;}
    public function prepareSocialVideos(Account $account, Post $post): bool {return $this->ready;}
}
function verifyV7(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);echo "PASS $message\n";}
$account=Account::withoutGlobalScopes()->where('provider','twitter')->firstOrFail();
WorkspaceManager::setCurrent(Workspace::findOrFail($account->workspace_id));
DB::beginTransaction();
try {
    $source=Post::firstOrFail();
    $post=$source->replicate();$post->uuid=(string)Illuminate\Support\Str::uuid();$post->status=1;$post->schedule_status=1;$post->published_at=null;$post->publishing_runs=1;$post->saveQuietly();
    $post->accounts()->attach($account->id,['status'=>PostAccountStatus::PUBLISHING->value]);
    $post->versions()->create(['account_id'=>0,'is_original'=>true,'content'=>[
        ['body'=>'First item','media'=>[]],['body'=>'<div><br></div>','media'=>[]],['body'=>'Second item','media'=>[]]
    ],'options'=>[]]);
    $post->load('versions','accounts','workspace');
    $provider=new V7ProviderFixture(Illuminate\Http\Request::create('/'),'', '', '', []);
    $action=new V7ActionFixture($provider);
    $provider->responses=[new SocialProviderResponse(Result::OK,['id'=>'fixture-first']),new SocialProviderResponse(Result::ERROR,['fixture temporary failure'])];
    $result=$action($account,$post);
    verifyV7($result->hasError()&&array_column($provider->calls,0)===['First item','Second item'],'old blank additional item is removed before v7 requirement validation and publishing');
    verifyV7($post->publishedProviderPostIds($account)===['fixture-first'],'first successful thread item is checkpointed before later failure');
    $provider->calls=[];$provider->responses=[new SocialProviderResponse(Result::OK,['id'=>'fixture-second'])];
    $post->resetAccountPublishState($account);$post->setAccountPublishStatus($account,PostAccountStatus::PUBLISHING);
    $result=$action($account,$post);
    verifyV7($result->isOk()&&array_column($provider->calls,0)===['Second item'],'retry sends only the unfinished thread item');
    verifyV7($post->accountPublishStatus($account)===PostAccountStatus::PUBLISHED,'successful retry settles the destination');
    // A separate unpublished post exercises asynchronous checkpoint resume without re-upload.
    $pending=$source->replicate();$pending->uuid=(string)Illuminate\Support\Str::uuid();$pending->status=1;$pending->schedule_status=1;$pending->published_at=null;$pending->publishing_runs=1;$pending->saveQuietly();
    $pending->accounts()->attach($account->id,['status'=>PostAccountStatus::PUBLISHING->value]);
    $pending->versions()->create(['account_id'=>0,'is_original'=>true,'content'=>[['body'=>'Pending item','media'=>[]]],'options'=>[]]);
    $pending->load('versions','accounts','workspace');$provider->calls=[];
    $provider->responses=[new SocialProviderResponse(Result::PENDING,['resume'=>['stage'=>'processing','state'=>['container_id'=>'fixture-container']]],false,30)];
    verifyV7($action($account,$pending)->isPending()&&$pending->hasPublishState($account),'pending provider operation is persisted');
    $action->ready=false;$provider->responses=[new SocialProviderResponse(Result::OK,['id'=>'fixture-completed'])];
    verifyV7($action($account,$pending)->isOk(),'checkpoint resume does not trigger a fresh video preparation gate');
    verifyV7(($provider->calls[1][1]['resume']['state']['container_id']??null)==='fixture-container','provider receives the original container checkpoint');
} finally {DB::rollBack();}
echo "All v7 publishing compatibility fixtures rolled back; no external publication.\n";
