<?php
// Run against the installed v7 candidate. Events/mail/queues are faked; no publication occurs.
require '/var/www/html/vendor/autoload.php';
$app=require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(Throwable $e){fwrite(STDERR,(string)$e);exit(1);});
use Illuminate\Support\Facades\{Notification,Event,Log};
use Inovector\Mixpost\Models\{Post,Account,Workspace};
use Inovector\Mixpost\Actions\Post\FinalizePostPublishing;
use Inovector\Mixpost\Facades\SocialProviderManager;
Event::fake(); Log::swap(new Psr\Log\NullLogger);
class NotificationPostFixture extends Post {
    public function __construct(array $attributes=[]) {parent::__construct(array_merge(['status'=>1,'published_at'=>null,'scheduled_at'=>null],$attributes));}
    public bool $errorsPresent=false;
    public bool $pending=false;
    public int $runs=0;
    public string $state='processing';
    public function withPublishingLock(Closure $callback): mixed {return $callback();}
    public function endPublishingRun(): int {return $this->runs;}
    public function hasPendingAccounts(): bool {return $this->pending;}
    public function isScheduleProcessing(): bool {return $this->state==='processing';}
    public function setSchedulePending(): void {$this->state='pending';}
    public function setPublished(): void {$this->state='published';}
    public function setFailed(): void {$this->state='failed';}
    public function hasErrors(): bool {return $this->errorsPresent;}
    public function statusName(): string {return $this->state;}
    public function fresh($with=[]) {return $this;}
}
function checkNotification(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function sentAlerts() {return collect(Notification::getFacadeRoot()->sentNotifications())->flatten(3)->filter(fn($row)=>is_array($row)&&isset($row['notification']));}
$providers=array_keys(SocialProviderManager::providers());
foreach($providers as $provider){
    Notification::fake();
    $p=new NotificationPostFixture;$p->id=999999;$p->uuid='fixture-post';$p->errorsPresent=true;
    $p->setRelation('workspace',(new Workspace)->forceFill(['uuid'=>'fixture-workspace']));
    $failed=(new Account)->forceFill(['id'=>1,'provider'=>$provider,'name'=>'Failed destination']);
    $failed->setRelation('pivot',new Illuminate\Database\Eloquent\Relations\Pivot(['errors'=>['provider_failure_fixture']]));
    $success=(new Account)->forceFill(['id'=>2,'provider'=>$provider,'name'=>'Successful destination']);
    $success->setRelation('pivot',new Illuminate\Database\Eloquent\Relations\Pivot(['errors'=>null,'provider_post_id'=>'already-live']));
    $p->setRelation('accounts',collect([$failed,$success]));
    (new FinalizePostPublishing)($p);
    checkNotification($p->state==='failed' && sentAlerts()->count()===1,$provider.': one failure alert');
    $entry=sentAlerts()->first();$n=$entry['notification'];
    checkNotification($entry['notifiable']->routes['mail']==='socials@ducatix.com','recipient preserved');
    checkNotification($n->delay->isFuture() && $n instanceof Inovector\Mixpost\Contracts\QueueWorkspaceAware,'delay and workspace preserved');
    $mail=$n->toMail($entry['notifiable']);$body=implode(' ',$mail->introLines);
    checkNotification(str_contains($body,$failed->providerName()) && str_contains($body,'Failed destination') && !str_contains($body,'Successful destination'),'only failed destination appears');
    checkNotification(str_contains($mail->actionUrl,'fixture-workspace')&&str_contains($mail->actionUrl,'fixture-post'),'correct post URL');
}
foreach([[false,false,0,false,'published',0],[false,false,0,true,'failed',1],[true,true,0,false,'pending',0],[true,false,1,false,'processing',0]] as [$errors,$pending,$runs,$batchErrors,$state,$count]) {
    Notification::fake();$p=new NotificationPostFixture;$p->id=999999;$p->uuid='fixture-post';$p->errorsPresent=$errors;$p->pending=$pending;$p->runs=$runs;
    $p->setRelation('workspace',(new Workspace)->forceFill(['uuid'=>'fixture-workspace']));$p->setRelation('accounts',collect());
    (new FinalizePostPublishing)($p,$batchErrors);
    checkNotification($p->state===$state && sentAlerts()->count()===$count,'success, exhausted job, staggered departure and overlapping run outcomes');
}
echo 'PASS: all '.count($providers).' providers; successful silence; exhausted failures; staggered schedules; concurrent runs; destination filtering; workspace links'.PHP_EOL;
