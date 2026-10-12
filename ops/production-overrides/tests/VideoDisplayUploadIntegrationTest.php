<?php
require '/var/www/html/vendor/autoload.php';
$app=require '/var/www/html/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(app()->environment()!=='testing'||!str_starts_with(config('database.connections.mysql.host'),'mixpost-local-rehearsal-'))throw new RuntimeException('Isolated rehearsal only');
set_exception_handler(function(Throwable $e){fwrite(STDERR,(string)$e);exit(1);});
use Illuminate\Support\Facades\{Auth,Bus,Event,Http,Storage};
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Support\{LocalChunkedUpload,LocalMediaStaging,MediaUploader};
use Inovector\Mixpost\Jobs\ConvertMediaVideoJob;
use Inovector\Mixpost\Models\Workspace;
Event::fake([Inovector\Mixpost\Events\Media\MediaUpdated::class]);Bus::fake();Http::preventStrayRequests();
WorkspaceManager::setCurrent(Workspace::firstOrFail());$userClass=config('auth.providers.users.model');Auth::login($userClass::firstOrFail());
config(['mixpost.disk'=>'public','cache.default'=>'array']);
$original='/fixture/original.mp4';$bytes=file_get_contents($original);
$upload=new LocalChunkedUpload;$session=$upload->initiate('original.mp4','video/mp4',strlen($bytes));
for($i=0;$i<$session['total_chunks'];$i++){
 $path=tempnam('/tmp','aspect-chunk-');file_put_contents($path,substr($bytes,$i*$session['chunk_size'],$session['chunk_size']));
 $upload->uploadChunk($session['upload_uuid'],$i,new Illuminate\Http\UploadedFile($path,'chunk.bin','application/octet-stream',null,true));
}
$media=$upload->completeAndStore($session['upload_uuid'],fn($source,$id)=>LocalMediaStaging::store(MediaUploader::fromSource($source)->path('ops-aspect-rehearsal')->data(['width'=>2156,'height'=>2160])->deferVideoConversion(),$id));
(new ConvertMediaVideoJob($media))->handle();$media->refresh();
if(($media->data['width']??null)!==2156||($media->data['height']??null)!==3840)throw new RuntimeException('Display dimensions incorrect after real conversion job: '.json_encode($media->data));
$thumb=$media->getConversion('thumb');if(!$thumb)throw new RuntimeException('No generated thumbnail');
$thumbPath=Storage::disk($thumb['disk'])->path($thumb['path']);[$w,$h]=getimagesize($thumbPath);
if(abs(($w/$h)/(539/960)-1)>0.01||$w>430||$h>430)throw new RuntimeException('Generated thumbnail has wrong proportions');
if(hash_file('sha256',Storage::disk($media->disk)->path($media->path))!==hash_file('sha256',$original))throw new RuntimeException('Source bytes were altered');
$resource=(new Inovector\Mixpost\Http\Base\Resources\MediaResource($media))->fields();
if($resource['width']!==2156||$resource['height']!==3840)throw new RuntimeException('Library response does not expose display dimensions');
copy($thumbPath,'/audit/original-corrected-thumbnail.jpg');
file_put_contents('/audit/original-upload-result.json',json_encode(['passed'=>true,'sourceBytesUnchanged'=>true,'chunkedUploadCompleted'=>true,'conversionJobPassed'=>true,'mediaResourceDimensions'=>[$resource['width'],$resource['height']],'thumbnail'=>[$w,$h],'originalDurationMs'=>$media->data['duration_ms']??null,'productionRecordsChanged'=>false],JSON_PRETTY_PRINT));
echo "PASS original through chunked acceptance, conversion, portrait thumbnail, and library response; source bytes unchanged\n";
