<?php

// Run only against an isolated restored DB on a Docker internal network.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (app()->environment() !== 'testing' || ! str_starts_with(config('database.connections.mysql.host'), 'mixpost-local-rehearsal-')) {
    throw new RuntimeException('Refusing integration test outside isolated rehearsal');
}
set_exception_handler(function (Throwable $error): void { fwrite(STDERR, (string) $error); exit(1); });

use Aws\Command;
use Aws\Result;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Psr7\Response;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\{Auth, Bus, Event, Http, Storage};
use Inovector\Mixpost\Actions\Post\AccountPublishPost;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Jobs\DeliverStagedMediaJob;
use Inovector\Mixpost\Models\{Account, Media, Post, PostVersion, Workspace};
use Inovector\Mixpost\Support\{LocalChunkedUpload, LocalMediaStaging, MediaUploader, MediaSource};

class NoNetworkStorage extends S3Client
{
    public array $objects = [];
    public int $calls = 0;
    public int $writes = 0;
    public bool $stalled = false;
    public bool $loseAck = false;
    public function headObject(array $request): Result
    {
        $this->calls++;
        if ($this->stalled) { throw new RuntimeException('Simulated provider stall'); }
        if (! isset($this->objects[$request['Key']])) {
            throw new S3Exception('Missing', new Command('HeadObject'), ['response' => new Response(404)]);
        }
        $bytes = $this->objects[$request['Key']];
        return new Result(['ContentLength' => strlen($bytes), 'ETag' => '"'.md5($bytes).'"']);
    }
    public function putObject(array $request): Result
    {
        $this->calls++; $this->writes++;
        $this->objects[$request['Key']] = stream_get_contents($request['Body']);
        if ($this->loseAck) { $this->loseAck = false; throw new RuntimeException('Simulated accepted PUT with lost acknowledgment'); }
        return new Result;
    }
    public function execute(\Aws\CommandInterface $command) { if ($command->getName() === 'DeleteObject') { unset($this->objects[$command['Key']]); return new Result; } throw new RuntimeException('Unexpected storage command '.$command->getName()); }
    public function deleteObject(array $request): Result { unset($this->objects[$request['Key']]); return new Result; }
}
$cloud = new NoNetworkStorage(['service' => 's3', 'version' => 'latest', 'region' => 'hel1', 'credentials' => ['key' => 'isolated', 'secret' => 'isolated'], 'endpoint' => 'http://unreachable.invalid']);
Storage::extend('ops-rehearsal-s3', function ($app, $config) use ($cloud): AwsS3V3Adapter {
    $adapter = new League\Flysystem\AwsS3V3\AwsS3V3Adapter($cloud, 'isolated');
    return new AwsS3V3Adapter(new League\Flysystem\Filesystem($adapter), $adapter, $config + ['bucket' => 'isolated'], $cloud);
});
config(['filesystems.disks.rehearsal-cloud' => ['driver' => 'ops-rehearsal-s3', 'bucket' => 'isolated'],
    'mixpost.disk' => 'rehearsal-cloud', 'cache.default' => 'array']);
Event::fake([Inovector\Mixpost\Events\Media\MediaUpdated::class]); Bus::fake(); Http::preventStrayRequests();
$workspace = Workspace::firstOrFail();
WorkspaceManager::setCurrent($workspace);
$userClass = config('auth.providers.users.model');
$user = $userClass::firstOrFail(); Auth::login($user);
function verifyStaging(bool $condition, string $label): void
{
    if (! $condition) { throw new RuntimeException($label); }
    echo 'PASS '.$label.PHP_EOL;
}
function retryNow(Media $media): void
{
    $media->refresh(); $data = $media->data; $data['peachy_storage']['next_attempt_at'] = 0;
    $media->forceFill(['data' => $data])->save();
}
function fixturePost(Media $media): Post
{
    $post = new Post;
    $version = new PostVersion(['account_id' => 0, 'is_original' => true,
        'content' => [['body' => 'Isolated fixture', 'media' => [$media->id], 'url' => null]], 'options' => []]);
    $post->setRelation('versions', collect([$version]));
    return $post;
}
$bytes = str_repeat('fixture-', 1572864); // Exactly 12 MiB.
$phase = getenv('PEACHY_REHEARSAL_PHASE');
$checkpointPath = storage_path('app/peachy-upload-state/rehearsal-checkpoint.json');
if ($phase === 'resume') {
    $checkpoint = json_decode(file_get_contents($checkpointPath), true, flags: JSON_THROW_ON_ERROR);
    $persisted = Media::findOrFail($checkpoint['media_id']);
    verifyStaging(! LocalMediaStaging::ready($persisted) && Storage::disk('public')->get($persisted->path) === $bytes,
        'accepted 12 MiB survives replacement of the app container');
    $restored = new LocalChunkedUpload;
    $open = $restored->getSessionData($checkpoint['open_uuid']);
    verifyStaging($open['total_size'] === strlen($bytes), 'unfinished session survives replacement of the app container');
    for ($i = 1; $i < $open['total_chunks']; $i++) {
        $path = tempnam('/tmp', 'restart-chunk-');
        file_put_contents($path, substr($bytes, $i * $open['chunk_size'], $open['chunk_size']));
        $restored->uploadChunk($checkpoint['open_uuid'], $i, new Illuminate\Http\UploadedFile($path, 'chunk.bin', 'application/octet-stream', null, true));
    }
    $resumed = $restored->completeAndStore($checkpoint['open_uuid'], fn ($source, $id) => LocalMediaStaging::store(MediaUploader::fromSource($source)->path('ops-rehearsal/resumed'), $id));
    verifyStaging($cloud->calls === 0 && Storage::disk('public')->get($resumed->path) === $bytes, 'unfinished upload completes after container replacement without storage');
    LocalMediaStaging::deliver($persisted); $persisted->refresh();
    verifyStaging(LocalMediaStaging::ready($persisted) && $cloud->objects[$persisted->path] === $bytes, 'accepted outbox delivers after container replacement');
    $cloud->calls = 0; $cloud->writes = 0;
}
$upload = new LocalChunkedUpload;
$session = $upload->initiate('local-fixture.bin', 'application/octet-stream', strlen($bytes));
$uuid = $session['upload_uuid'];
verifyStaging($cloud->calls === 0 && $upload->getSessionData($uuid)['is_s3'] === false, 'initiation makes no storage request');
for ($i = 0; $i < $session['total_chunks']; $i++) {
    $path = tempnam('/tmp', 'local-rehearsal-');
    file_put_contents($path, substr($bytes, $i * $session['chunk_size'], $session['chunk_size']));
    $file = new Illuminate\Http\UploadedFile($path, 'chunk.bin', 'application/octet-stream', null, true);
    $upload->uploadChunk($uuid, $i, $file);
}
verifyStaging($cloud->calls === 0, 'chunk receipt makes no storage request');
$reopened = new LocalChunkedUpload;
verifyStaging($reopened->getSessionData($uuid)['total_size'] === strlen($bytes)
    && is_dir(storage_path('app/peachy-upload-state/chunked/'.$uuid)), 'new upload instance reads persistent session');
$store = fn ($source, $id) => LocalMediaStaging::store(MediaUploader::fromSource($source)->path('ops-rehearsal/'.$uuid)->deferVideoConversion(), $id);
$media = $reopened->completeAndStore($uuid, $store);
verifyStaging($cloud->calls === 0 && Storage::disk('public')->get($media->path) === $bytes
    && ! LocalMediaStaging::ready($media), 'completion accepts durable local bytes without storage');
if ($phase === 'stage') {
    $open = (new LocalChunkedUpload)->initiate('restart.bin', 'application/octet-stream', strlen($bytes));
    $path = tempnam('/tmp', 'restart-first-'); file_put_contents($path, substr($bytes, 0, $open['chunk_size']));
    (new LocalChunkedUpload)->uploadChunk($open['upload_uuid'], 0, new Illuminate\Http\UploadedFile($path, 'chunk.bin', 'application/octet-stream', null, true));
    file_put_contents($checkpointPath, json_encode(['media_id' => $media->id, 'open_uuid' => $open['upload_uuid']]));
    echo "PASS accepted bytes and unfinished chunk persisted before container replacement\n";
    exit(0);
}
$again = (new LocalChunkedUpload)->completeAndStore($uuid, $store);
verifyStaging($again->id === $media->id && Media::where('data->peachy_storage->upload_uuid', $uuid)->count() === 1, 'duplicate completion returns one media record');
Auth::login($userClass::findOrFail($user->id));
$originalUserId = $user->id; $user->id = $originalUserId + 1000000; Auth::setUser($user);
$denied = false;
try { (new LocalChunkedUpload)->getSessionData($uuid); }
catch (Inovector\Mixpost\Exceptions\ChunkedUploadSessionNotFound $error) { $denied = true; }
verifyStaging($denied, 'another user cannot read persistent session');
$user->id = $originalUserId; Auth::setUser($user);
$action = new AccountPublishPost;
foreach (['twitter', 'instagram', 'threads', 'bluesky', 'facebook_page', 'youtube', 'tiktok'] as $provider) {
    $account = new Account(['provider' => $provider]); $account->id = 999999;
    verifyStaging(! $action->prepareStorage($account, fixturePost($media)), 'storage preflight blocks '.$provider);
    verifyStaging($action($account, fixturePost($media))->hasError(), 'direct publisher refuses pending '.$provider);
}
class WaitingStoragePost extends Post {
    public array $capturedErrors = [];
    public function accountPublishStatus(Account $account): Inovector\Mixpost\Enums\PostAccountStatus { return Inovector\Mixpost\Enums\PostAccountStatus::PUBLISHING; }
    public function insertErrors(Account $account, $errors): void { $this->capturedErrors = $errors; }
}
class WaitingStorageAccount extends Account {
    public function isServiceActive(): bool { return true; }
    public function isUnauthorized(): bool { return false; }
}
class WaitingStorageWorker extends Inovector\Mixpost\Jobs\AccountPublishPostJob {
    public ?int $releasedFor = null;
    public function batch() { return new class { public function cancelled() { return false; } }; }
    public function release($delay = 0) { $this->releasedFor = $delay; }
}
$waitingPost = new WaitingStoragePost; $waitingPost->id = 999999;
$waitingPost->setRelation('versions', fixturePost($media)->versions);
$waitingAccount = new WaitingStorageAccount(['provider' => 'youtube']); $waitingAccount->id = 999998;
$waitingJob = new WaitingStorageWorker($waitingAccount, $waitingPost); $waitingJob->handle($action);
verifyStaging($waitingJob->releasedFor === 30 && $waitingPost->capturedErrors === [], 'queued publisher waits for storage without calling provider');
Illuminate\Support\Facades\Cache::put('media-storage-wait:999999:999998', time() - 3601, 7200);
$waitingJob = new WaitingStorageWorker($waitingAccount, $waitingPost); $waitingJob->handle($action);
verifyStaging($waitingJob->releasedFor === null && count($waitingPost->capturedErrors) === 1
    && Storage::disk('public')->exists($media->path), 'queued storage deadline reports failure and retains local media');
$cloud->stalled = true;
LocalMediaStaging::deliver($media);
$media->refresh();
verifyStaging(! LocalMediaStaging::ready($media) && is_file(Storage::disk('public')->path($media->path))
    && $media->data['peachy_storage']['attempts'] === 1, 'provider stall retains local file and retry outbox');
$cloud->stalled = false; $cloud->loseAck = true; retryNow($media);
LocalMediaStaging::deliver($media);
$media->refresh();
verifyStaging(! LocalMediaStaging::ready($media) && $cloud->writes === 1, 'lost acknowledgment keeps pending pointer');
retryNow($media); LocalMediaStaging::deliver($media); $media->refresh();
verifyStaging(LocalMediaStaging::ready($media) && $media->disk === 'rehearsal-cloud'
    && $cloud->objects[$media->path] === $bytes && $cloud->writes === 1
    && ! Storage::disk('public')->exists($media->path), 'retry reconciles verified bytes promotes once and cleans local file');
verifyStaging($action->prepareStorage(new Account(['provider' => 'youtube']), fixturePost($media)), 'verified storage permits publishing preflight');
// Reproduce a worker stopping after database promotion but before local cleanup.
Storage::disk('public')->put($media->path, $bytes);
$data = $media->data; $data['peachy_storage']['local_files'] = [['disk' => 'public', 'path' => $media->path]];
$media->forceFill(['data' => $data])->save(); $calls = $cloud->calls;
LocalMediaStaging::deliver($media); $media->refresh();
verifyStaging($cloud->calls === $calls && ! Storage::disk('public')->exists($media->path)
    && ! LocalMediaStaging::needsWork($media), 'restart after promotion only completes cleanup');
verifyStaging(config('queue.connections.peachy-media.retry_after') > config('horizon.defaults.peachy-media.timeout')
    && collect(config('horizon.defaults'))->except('peachy-media')->every(fn ($supervisor) => ! in_array('mixpost-media', $supervisor['queue'] ?? [])), 'media worker timeout is below its isolated retry lease');
$dispatcher = Bus::getFacadeRoot();
Bus::swap(new class($app) extends Illuminate\Bus\Dispatcher { public function dispatch($command) { throw new RuntimeException('Simulated unavailable queue'); } });
$smallPath = tempnam('/tmp', 'outbox-test-'); file_put_contents($smallPath, 'queue-outbox');
$outbox = LocalMediaStaging::store(MediaUploader::fromSource(MediaSource::fromLocalPath($smallPath, 'queue.bin'))->path('ops-rehearsal/outbox'));
Bus::swap($dispatcher);
verifyStaging($outbox->exists && ! LocalMediaStaging::ready($outbox)
    && Storage::disk('public')->get($outbox->path) === 'queue-outbox', 'queue failure still acknowledges durable accepted media');
LocalMediaStaging::dispatchPending();
verifyStaging(Bus::dispatched(DeliverStagedMediaJob::class)->contains(fn ($job) => $job->mediaId === $outbox->id), 'outbox scanner queues accepted media');
verifyStaging(true, 'scheduler recovers undispatched outbox');
unlink($smallPath);
echo json_encode(['passed' => true, 'bytes' => strlen($bytes), 'mediaCountForUpload' => 1,
    'externalNetworkRequired' => false, 'publicationInvoked' => false]).PHP_EOL;

$videoPath = '/tmp/local-rehearsal-video.mp4';
exec('ffmpeg -nostdin -v error -y -f lavfi -i color=c=black:s=320x240:d=2 -c:v libx264 -pix_fmt yuv420p '.escapeshellarg($videoPath), $output, $code);
verifyStaging($code === 0, 'valid video fixture generated');
$padding = 12582912 - filesize($videoPath);
file_put_contents($videoPath, pack('N', $padding).'free'.str_repeat("\0", $padding - 8), FILE_APPEND);
$video = LocalMediaStaging::store(MediaUploader::fromLocalPath($videoPath, 'fixture-12mb.mp4')->path('ops-rehearsal/video')->deferVideoConversion());
verifyStaging($video->mime_type === 'video/mp4' && $video->size === 12582912 && $video->isProcessing(), '12 MiB video is accepted before conversion');
LocalMediaStaging::deliver($video); $video->refresh();
verifyStaging(LocalMediaStaging::ready($video) && ! $video->isProcessing()
    && collect($video->conversions)->contains(fn ($item) => $item['name'] === 'thumb')
    && collect($video->conversions)->contains(fn ($item) => $item['name'] === 'social_video')
    && collect($video->conversions)->every(fn ($item) => $item['disk'] === 'rehearsal-cloud' && isset($cloud->objects[$item['path']])), 'video and all prepared derivatives promote together');
unlink($videoPath);

$pngPath = '/tmp/local-rehearsal-image.png';
$image = imagecreatetruecolor(48, 48); imagepng($image, $pngPath); imagedestroy($image);
$uploads = new Inovector\Mixpost\Http\Base\Requests\Workspace\MediaUploadFile;
$uploads->files->set('file', new Illuminate\Http\UploadedFile($pngPath, 'fixture.png', 'image/png', null, true));
$uploads->setContainer($app);
$calls = $cloud->calls;
$imageMedia = $uploads->handle();
verifyStaging($imageMedia->disk === 'public' && ! LocalMediaStaging::ready($imageMedia) && $cloud->calls === $calls
    && count($imageMedia->conversions) === 1, 'ordinary image request accepts original and thumbnail without storage');
$copies = (new Inovector\Mixpost\Actions\Media\CopyMedia)([$imageMedia->id]);
verifyStaging(count($copies) === 1 && $copies[0]->path !== $imageMedia->path
    && $copies[0]->data['peachy_storage']['upload_uuid'] === null
    && ! LocalMediaStaging::ready($copies[0]), 'pending copy has independent local bytes and outbox');
$held = fopen(storage_path('app/peachy-upload-state/locks/'.$imageMedia->id.'.lock'), 'c'); flock($held, LOCK_EX);
verifyStaging((new Inovector\Mixpost\Actions\Media\CopyMedia)([$imageMedia->id]) === [], 'copy cannot race an active delivery');
flock($held, LOCK_UN); fclose($held);
LocalMediaStaging::deliver($imageMedia); LocalMediaStaging::deliver($copies[0]);
verifyStaging(LocalMediaStaging::ready($imageMedia->refresh()) && LocalMediaStaging::ready($copies[0]->refresh())
    && isset($cloud->objects[$imageMedia->path], $cloud->objects[$copies[0]->path]), 'original and copy deliver independently');
$docId = 'isolated-'.Illuminate\Support\Str::uuid();
$edited = LocalMediaStaging::store(MediaUploader::fromLocalPath($pngPath)->path('ops-rehearsal/edit')->data(['adobe_express_doc_id' => $docId]));
LocalMediaStaging::deliver($edited); $edited->refresh(); $oldPath = $edited->path;
$updated = LocalMediaStaging::store(MediaUploader::fromLocalPath($pngPath)->path('ops-rehearsal/edit')->data(['adobe_express_doc_id' => $docId]));
verifyStaging($updated->id === $edited->id && isset($cloud->objects[$oldPath]) && ! LocalMediaStaging::ready($updated), 'Adobe replacement retains previous cloud file while pending');
$refused = false;
try { LocalMediaStaging::store(MediaUploader::fromLocalPath($pngPath)->path('ops-rehearsal/edit')->data(['adobe_express_doc_id' => $docId])); }
catch (Illuminate\Validation\ValidationException $error) { $refused = true; }
verifyStaging($refused, 'pending Adobe replacement cannot be overwritten by another edit');
LocalMediaStaging::deliver($updated); $updated->refresh();
verifyStaging(LocalMediaStaging::ready($updated) && ! isset($cloud->objects[$oldPath]) && isset($cloud->objects[$updated->path]), 'verified Adobe replacement cleans only superseded cloud file');
unlink($pngPath);
echo "All isolated local upload integration checks passed.\n";

$movPath = '/tmp/local-rehearsal.mov';
exec('ffmpeg -nostdin -v error -y -f lavfi -i color=c=black:s=160x240:d=1 -c:v libx264 '.escapeshellarg($movPath), $output, $code);
verifyStaging($code === 0, 'non-MP4 video fixture generated');
$movBytes = file_get_contents($movPath);
$mov = LocalMediaStaging::store(MediaUploader::fromLocalPath($movPath)->path('ops-rehearsal/mov')->deferVideoConversion());
$cloud->stalled = true;
LocalMediaStaging::deliver($mov); $mov->refresh();
$retained = $mov->data['peachy_storage']['retained_local_files'][0]['path'] ?? null;
verifyStaging(! LocalMediaStaging::ready($mov) && $mov->mime_type === 'video/mp4' && $retained
    && Storage::disk('public')->get($retained) === $movBytes, 'storage outage retains accepted non-MP4 source after local conversion');
$cloud->stalled = false; retryNow($mov); LocalMediaStaging::deliver($mov); $mov->refresh();
verifyStaging(LocalMediaStaging::ready($mov) && ! Storage::disk('public')->exists($retained)
    && count($mov->data['peachy_storage']['local_files']) === 0, 'verified converted video permits retained-source cleanup');
unlink($movPath);
echo "All isolated local upload integration checks passed.\n";
