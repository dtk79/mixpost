<?php
// Installed Pro dependencies; fake HTTP, logging, and clocks. No database writes.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e) { fwrite(STDERR, (string) $e); exit(1); });
require __DIR__.'/../ManagesInstagramResources.php';
use Illuminate\Support\Facades\{Http, Log};
use Inovector\Mixpost\Support\SocialProviderResponse;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus as Status;
Log::swap(new Psr\Log\NullLogger);
Http::preventStrayRequests();
class InstagramFixture {
    use Inovector\Mixpost\SocialProviders\Meta\Concerns\ManagesInstagramResources;
    public array $values = ['provider_id'=>'fixture-account'];
    public array $states = [];
    public int $checks=0;
    public int $waits=0;
    public function response($status, array $context): SocialProviderResponse { return new SocialProviderResponse($status,$context); }
    public function getContainer($id): SocialProviderResponse {
        $this->checks++;
        $state=count($this->states)>1 ? array_shift($this->states) : $this->states[0];
        return $this->response(Status::OK,$state);
    }
    protected function waitForInstagramContainerPoll(): void { $this->waits++; }
    protected function resolveApiDomain(): string { return 'https://graph.test'; }
    protected function getAccessToken(): array { return ['access_token'=>'fixture-token']; }
    protected function http(): string { return Http::class; }
    protected function buildResponse($r): SocialProviderResponse { return $this->response($r->successful()?Status::OK:Status::ERROR,$r->json()); }
}
function checkInstagram(bool $ok,string $message): void { if(!$ok) throw new RuntimeException($message); }
foreach(['ERROR','EXPIRED','PUBLISHED','UNKNOWN','IN_PROGRESS',null] as $state) {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake();
    $p=new InstagramFixture;
    $p->states=[['status_code'=>$state,'status'=>'Error: Media upload has failed with error code 2207082']];
    $r=$p->publishContainer('container-fixture');
    checkInstagram($r->hasError(), 'Non-ready state must fail safely');
    checkInstagram(count(Http::recorded())===0, 'Non-ready state must never call media_publish');
    if($state==='ERROR') checkInstagram($r->context()['phase']==='instagram_container_processing' && $r->context()['container_id']==='container-fixture','Terminal failure must retain provenance');
    if($state==='IN_PROGRESS') checkInstagram($p->checks===12 && $p->waits===11,'Processing wait must be bounded');
}
Http::swap(new Illuminate\Http\Client\Factory);
Http::fake(['graph.test/*'=>Http::response(['id'=>'published-fixture'])]);
$p=new InstagramFixture;
$p->states=[['status_code'=>'IN_PROGRESS'],['status_code'=>'FINISHED']];
$r=$p->publishContainer('container-ready');
checkInstagram($r->id()==='published-fixture' && count(Http::recorded())===1,'Ready container publishes once');
checkInstagram(Http::recorded()[0][0]['creation_id']==='container-ready','Publish uses checked container');
Http::swap(new Illuminate\Http\Client\Factory);
Http::fake(['graph.test/*'=>Http::response(['error'=>['message'=>'2207082']],400)]);
$p=new InstagramFixture;$p->states=[['status_code'=>'FINISHED']];
$r=$p->publishContainer('container-ready');
checkInstagram($r->hasError() && !isset($r->context()['phase']),'Publish endpoint errors must never be marked retryable upload failures');
echo "PASS: terminal provenance; expired/published/unknown/pending guards; bounded polling; single publication; no publish-error retry marker\n";

// Preserve the live media-download retry safeguard found during source reconciliation.
class InstagramDownloadFixture extends InstagramFixture {
    public array $downloadWaits = [];
    public function create(): SocialProviderResponse { return $this->createInstagramMediaContainer(['image_url'=>'https://media.test/fixture.jpg'],30); }
    protected function waitForInstagramMediaDownloadRetry(int $attempt): void { $this->downloadWaits[] = $attempt; }
}
$downloadFailure = ['error'=>['code'=>-2, 'error_subcode'=>2207003, 'message'=>'Rejected before container creation']];
Http::swap(new Illuminate\Http\Client\Factory);
Http::fakeSequence()->push($downloadFailure,400)->push(['id'=>'accepted-container'],200);
$p = new InstagramDownloadFixture; $r = $p->create();
checkInstagram($r->isOk() && $r->id()==='accepted-container' && count(Http::recorded())===2 && $p->downloadWaits===[1], 'Definite pre-container rejection can retry once and recover');
Http::swap(new Illuminate\Http\Client\Factory);
Http::fake(['graph.test/*'=>Http::response($downloadFailure,400)]);
$p = new InstagramDownloadFixture; $r = $p->create();
checkInstagram($r->hasError() && count(Http::recorded())===3 && $p->downloadWaits===[1,2], 'Download rejection retries are capped at three requests');
foreach ([['error'=>['code'=>-1,'error_subcode'=>2207003]], ['error'=>['code'=>-2,'error_subcode'=>2207004]], ['id'=>'accepted-container']+$downloadFailure] as $unsafe) {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(['graph.test/*'=>Http::response($unsafe,400)]);
    $p = new InstagramDownloadFixture; $p->create();
    checkInstagram(count(Http::recorded())===1 && $p->downloadWaits===[], 'Other failures or an accepted container must not replay creation');
}
Http::swap(new Illuminate\Http\Client\Factory);
$calls = 0;
Http::fake(function () use (&$calls) { $calls++; throw new Illuminate\Http\Client\ConnectionException('Injected lost response'); });
$p = new InstagramDownloadFixture; $failed = false;
try { $p->create(); } catch (Illuminate\Http\Client\ConnectionException $error) { $failed = true; }
checkInstagram($failed && $calls===1 && $p->downloadWaits===[], 'Ambiguous transport failure must propagate without replay');
echo "PASS: Instagram media-download retry preservation (6 cases)\n";
