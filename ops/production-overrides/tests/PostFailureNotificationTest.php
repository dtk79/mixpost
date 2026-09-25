<?php
// Exercise batch finalization and email rendering without sending mail or changing posts.
require '/var/www/html/vendor/autoload.php';
$app=require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e) { fwrite(STDERR,(string)$e);exit(1); });
require __DIR__.'/../PostFailureExplanation.php';
require __DIR__.'/../PostPublishingFailedNotification.php';
require __DIR__.'/../PublishPost.php';
use Illuminate\Support\Facades\{Bus,Notification,Log};
use Inovector\Mixpost\Models\{Post,Account,Workspace};
use Inovector\Mixpost\Notifications\PostPublishingFailedNotification;
use Illuminate\Support\Testing\Fakes\BatchFake;
Log::swap(new Psr\Log\NullLogger);
class NotificationPostFixture extends Post {
    public bool $errorsPresent=false;
    public string $state='scheduled';
    public function isScheduleProcessing(): bool { return $this->state==='processing'; }
    public function setScheduleProcessing(bool $dispatchEvent = true): void { $this->state='processing'; }
    public function setPublished(): void { $this->state='published'; }
    public function setFailed(): void { $this->state='failed'; }
    public function hasErrors(): bool { return $this->errorsPresent; }
    public function fresh($with=[]) { return $this; }
}
function checkNotification(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
foreach([[true,0],[false,1],[false,0]] as [$providerErrors,$jobFailures]) {
    Bus::fake();Notification::fake();
    $p=new NotificationPostFixture;$p->id=999999;$p->uuid='fixture-post';$p->errorsPresent=$providerErrors;
    $p->setRelation('accounts',collect());
    $p->setRelation('workspace',(new Workspace)->forceFill(['uuid'=>'fixture-workspace']));
    (new Inovector\Mixpost\Actions\Post\PublishPost)($p);
    $pending=Bus::getFacadeRoot()->batched(fn($b)=>true)->first();
    checkNotification($pending!==null,'Publish schedules batch');
    checkNotification(count(Notification::getFacadeRoot()->sentNotifications())===0,'No alert before batch finishes');
    $batch=new BatchFake('fixture-batch','test',1,0,$jobFailures,[],[],Carbon\CarbonImmutable::now());
    $pending->options['finally'][0]($batch);
    // The fake stores on-demand notifications under an empty notifiable ID.
    $all=Notification::getFacadeRoot()->sentNotifications();
    $notifications=collect($all)->flatten(3)->filter(fn($entry)=>is_array($entry)&&isset($entry['notification']));
    if($providerErrors||$jobFailures){
        checkNotification($p->state==='failed' && $notifications->count()===1,'Provider and exhausted-job failures each send one alert');
        $entry=$notifications->first();$n=$entry['notification'];
        checkNotification($entry['notifiable']->routes['mail']==='socials@ducatix.com','Alert goes to configured recipient');
        checkNotification($n->delay->isFuture(),'Alert waits for final error persistence');
        checkNotification($n instanceof Inovector\Mixpost\Contracts\QueueWorkspaceAware,'Alert retains workspace context');
        checkNotification($n->viaQueues()['mail']===Inovector\Mixpost\Util::config('queue.default','mixpost-default'),'Alert uses a consumed mail queue');
    }else checkNotification($p->state==='published' && $notifications->isEmpty(),'Successful batch sends no failure alert');
}
$p=new NotificationPostFixture;$p->uuid='fixture-post';$p->errorsPresent=true;
$p->setRelation('workspace',(new Workspace)->forceFill(['uuid'=>'fixture-workspace']));
$a=(new Account)->forceFill(['provider'=>'instagram','name'=>'Rich Merritt','username'=>'richmerrittauthor']);
$a->setRelation('pivot',new Illuminate\Database\Eloquent\Relations\Pivot(['errors'=>['Error: Media upload has failed with error code 2207082']]));
$p->setRelation('accounts',collect([$a]));
$n=new PostPublishingFailedNotification($p);$mail=$n->toMail(new Illuminate\Notifications\AnonymousNotifiable);
checkNotification(str_contains(implode(' ',$mail->introLines),'2207082'),'Email retains useful provider explanation');
checkNotification(str_contains($mail->actionUrl,'fixture-workspace') && str_contains($mail->actionUrl,'fixture-post'),'Email links to exact workspace and post');
echo "PASS: batch completion, partial failure, exhausted jobs, successful silence, recipient, delay, workspace queue, explanation, exact post link\n";

// Discover every provider supported by the installed package, not just Instagram
// or the accounts connected today. Exercise the real shared batch finalizer.
$providers=array_keys(Inovector\Mixpost\Facades\SocialProviderManager::providers());
checkNotification(count($providers)>0,'Provider registry must not be empty');
foreach($providers as $provider){
    Bus::fake();Notification::fake();
    $p=new NotificationPostFixture;$p->id=999999;$p->uuid='fixture-post';$p->errorsPresent=true;
    $p->setRelation('workspace',(new Workspace)->forceFill(['uuid'=>'fixture-workspace']));
    $failed=(new Account)->forceFill(['id'=>1,'provider'=>$provider,'name'=>'Failed destination','username'=>'failed']);
    $failed->setRelation('pivot',new Illuminate\Database\Eloquent\Relations\Pivot(['errors'=>['provider_failure_fixture'],'provider_post_id'=>null]));
    $success=(new Account)->forceFill(['id'=>2,'provider'=>$provider,'name'=>'Successful destination','username'=>'successful']);
    $success->setRelation('pivot',new Illuminate\Database\Eloquent\Relations\Pivot(['errors'=>null,'provider_post_id'=>'already-published']));
    $p->setRelation('accounts',collect([$failed,$success]));
    (new Inovector\Mixpost\Actions\Post\PublishPost)($p);
    $pending=Bus::getFacadeRoot()->batched(fn($b)=>true)->first();
    $pending->options['finally'][0](new BatchFake('fixture-batch','test',2,0,0,[],[],Carbon\CarbonImmutable::now()));
    $sent=collect(Notification::getFacadeRoot()->sentNotifications())->flatten(3)->filter(fn($row)=>is_array($row)&&isset($row['notification']));
    checkNotification($sent->count()===1 && $p->state==='failed', $provider.': any failed destination must trigger one alert');
    $entry=$sent->first();
    checkNotification($entry['notifiable']->routes['mail']==='socials@ducatix.com',$provider.': configured recipient');
    $mail=$entry['notification']->toMail($entry['notifiable']);$body=implode(' ',$mail->introLines);
    checkNotification(str_contains($body,$failed->providerName()) && str_contains($body,'Failed destination') && str_contains($body,'Provider failure fixture'),$provider.': alert must identify platform/account/reason');
    checkNotification(!str_contains($body,'Successful destination'),$provider.': successful destinations must not be labeled failed');
}
// Multiple failed platforms are consolidated into one mail, with each failure listed.
Bus::fake();Notification::fake();
$p=new NotificationPostFixture;$p->id=999999;$p->uuid='fixture-post';$p->errorsPresent=true;
$p->setRelation('workspace',(new Workspace)->forceFill(['uuid'=>'fixture-workspace']));
$accounts=[];
foreach($providers as $i=>$provider){
    $a=(new Account)->forceFill(['id'=>$i+1,'provider'=>$provider,'name'=>'Failure destination '.$i]);
    $a->setRelation('pivot',new Illuminate\Database\Eloquent\Relations\Pivot(['errors'=>[$i%2 ? 'access_token_expired':'service_disabled']]));
    $accounts[]=$a;
}
$p->setRelation('accounts',collect($accounts));
(new Inovector\Mixpost\Actions\Post\PublishPost)($p);
$pending=Bus::getFacadeRoot()->batched(fn($b)=>true)->first();
$pending->options['finally'][0](new BatchFake('fixture-batch','test',count($accounts),0,0,[],[],Carbon\CarbonImmutable::now()));
$sent=collect(Notification::getFacadeRoot()->sentNotifications())->flatten(3)->filter(fn($row)=>is_array($row)&&isset($row['notification']));
checkNotification($sent->count()===1,'Multiple platform failures produce one consolidated alert');
$entry=$sent->first();$body=implode(' ',$entry['notification']->toMail($entry['notifiable'])->introLines);
foreach($accounts as $a)checkNotification(str_contains($body,$a->name),'Every failed account must appear');
echo 'PASS: all '.count($providers).' registered providers: '.implode(', ',$providers)."; mixed success/failure; multiple failures; expired connections; disabled services\n";
