<?php

// Run in the Pro container. Database fixtures are always rolled back; HTTP and storage are local fakes.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$source = getenv('PEACHY_THUMBNAIL_SOURCE');
if ($source) {
    foreach (['ImportInstagramMediaJob', 'ImportFacebookPagePostsJob', 'ImportThreadsPostsJob', 'DownloadImportedPostThumbnailJob'] as $class) {
        require $source.'/'.$class.'.php';
    }
}

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inovector\Mixpost\Jobs\DownloadImportedPostThumbnailJob;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Workspace;
use Inovector\Mixpost\SocialProviders\Meta\Jobs\ImportInstagramMediaJob;
use Inovector\Mixpost\SocialProviders\Meta\Jobs\ImportFacebookPagePostsJob;
use Inovector\Mixpost\SocialProviders\Threads\Jobs\ImportThreadsPostsJob;

function check($condition, string $message): void {
    if (! $condition) throw new RuntimeException($message);
    echo "PASS $message\n";
}
$root = '/tmp/thumbnail-reuse-test-'.bin2hex(random_bytes(8));
config(['cache.default'=>'array', 'mixpost.disk'=>'thumbnail_test', 'filesystems.disks.thumbnail_test'=>['driver'=>'local','root'=>$root]]);
$requests = 0;
Http::fake(function ($request, $options) use (&$requests) {
    $requests++;
    if (isset($options['sink'])) file_put_contents($options['sink'], 'test-image-content');
    return Http::response('test-image-content', 200, ['Content-Type'=>'image/jpeg']);
});
$account = Account::withoutGlobalScopes()->where('provider', 'instagram')->firstOrFail();
$workspace = Workspace::findOrFail($account->workspace_id);
DB::beginTransaction();
try {
    $workspace->execute(function () use ($account, $workspace, &$requests) {
        $id = '__thumbnail_regression_'.bin2hex(random_bytes(8));
        $query = fn()=>DB::table('mixpost_imported_posts')->where('account_id',$account->id)->where('provider_post_id',$id);
        $item = ['id'=>$id,'timestamp'=>'2026-09-01T00:00:00Z','created_time'=>'2026-09-01T00:00:00Z',
            'thumbnail_url'=>'https://example.invalid/new.jpg','media_url'=>'https://example.invalid/new.jpg',
            'full_picture'=>'https://example.invalid/new.jpg','caption'=>'refreshed','text'=>'refreshed','message'=>'refreshed'];
        foreach ([[ImportInstagramMediaJob::class,'importMedia'],[ImportFacebookPagePostsJob::class,'importPosts'],[ImportThreadsPostsJob::class,'importPosts']] as [$class,$method]) {
            $job = new $class($account);
            $call = new ReflectionMethod($class,$method);
            $query()->delete();
            $call->invoke($job,[$item],[]);
            check($query()->value('thumbnail')==='https://example.invalid/new.jpg', "$class inserts new thumbnails");
            $query()->update(['thumbnail'=>'imported/existing/preserved.jpg','text'=>'old']);
            $call->invoke($job,[$item],[]);
            $call->invoke($job,[$item],[]);
            check($query()->value('thumbnail')==='imported/existing/preserved.jpg' && $query()->value('text')==='refreshed', "$class repeat refresh preserves cached path and updates text");
            foreach ([null,'','https://example.invalid/expired.jpg'] as $previous) {
                $query()->update(['thumbnail'=>$previous]);$call->invoke($job,[$item],[]);
                check($query()->value('thumbnail')==='https://example.invalid/new.jpg', "$class refreshes uncached thumbnail ".var_export($previous,true));
            }
        }

        $job = new DownloadImportedPostThumbnailJob($account->uuid,[$id=>'https://example.invalid/new.jpg']);
        $path='imported/'.$workspace->uuid.'/'.$account->uuid.'/'.hash('sha256',$id).'.jpg';
        $job->handle();
        check($query()->value('thumbnail')===$path && Storage::disk('thumbnail_test')->exists($path), 'first download stores a stable path');
        check($requests===1, 'first download makes one HTTP request');
        $job->handle();check($requests===1, 'repeated job does not download again');
        $query()->update(['thumbnail'=>'https://example.invalid/rotated.jpg']);
        $job->handle();check($requests===1 && $query()->value('thumbnail')===$path, 'reuses stored file after interrupted database update');
        $query()->update(['thumbnail'=>'https://example.invalid/new.jpg']);Storage::disk('thumbnail_test')->delete($path);
        $lock=Cache::lock('mixpost:imported-thumbnail:'.$workspace->uuid.':'.$account->uuid.':'.hash('sha256',$id),360);
        $lock->get();
        try {$job->handle();check($requests===1 && ! Storage::disk('thumbnail_test')->exists($path), 'overlapping job is excluded by lock');} finally {$lock->release();}
        $job->handle();check($requests===2 && Storage::disk('thumbnail_test')->exists($path), 'download proceeds after lock release');
        $query()->delete();Storage::disk('thumbnail_test')->delete($path);
        $job->handle();check($requests===2 && ! Storage::disk('thumbnail_test')->exists($path), 'deleted post does not create an orphan');
    });
} finally {
    DB::rollBack();
    Illuminate\Support\Facades\File::deleteDirectory($root);
}
echo "All thumbnail reuse tests passed; database changes rolled back.\n";
