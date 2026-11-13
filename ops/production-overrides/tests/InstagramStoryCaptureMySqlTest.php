<?php

// Run only in the isolated restored MySQL rehearsal with HTTP/storage fakes.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, $error::class." (inspect isolated test evidence)\n");
    exit(1);
});

use App\Console\Commands\CaptureInstagramStories;
use App\Support\InstagramStoryCapture;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Workspace;
use Inovector\Mixpost\Util;

function mysqlStoryCheck(bool $ok, string $message): void {
    if (! $ok) throw new RuntimeException($message);
    echo "PASS $message\n";
}
if (! app()->environment('testing') || ! str_starts_with(config('database.connections.mysql.host'), 'mixpost-local-rehearsal-stories-')) {
    throw new RuntimeException('Isolated rehearsal required');
}
mysqlStoryCheck(Schema::hasColumns(InstagramStoryCapture::TABLE, ['workspace_id','account_id','provider_post_id','media_type','permalink','posted_at','disk','image_path','video_path','mime_type','width','height','captured_at','error']), 'MySQL migration provides the complete Annex schema');
mysqlStoryCheck(Util::config('disk') === 'public', 'rehearsal uses isolated local media disk');
Storage::fake('public'); Cache::flush();
$accounts = Account::withoutGlobalScopes()->whereIn('provider', ['instagram','instagram_standalone'])->where('authorized',true)->get()->filter(fn ($account) => $account->isServiceActive());
mysqlStoryCheck($accounts->count() >= 2, 'restored database contains multiple active authorized Instagram accounts');
$badId = (string) $accounts->first()->provider_id;
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j5WQAAAAASUVORK5CYII=');
$calls = 0;
$installFake = function (bool $expired = false) use (&$calls, $badId, $png): void {
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(function ($request) use (&$calls, $badId, $png, $expired) {
        $calls++;
        if (str_contains($request->url(), '/'.$badId.'/stories')) {
            return Http::response(['error'=>['code'=>190,'message'=>'Invalid test token']],401);
        }
        if (str_contains($request->url(), '/stories')) {
            $time = now('UTC')->subHours($expired ? 25 : 1)->toIso8601String();
            return Http::response(['data'=>[
                ['id'=>'90000000000000001','media_type'=>'IMAGE','media_url'=>'https://cdn.invalid/image.png','timestamp'=>$time],
                ['id'=>'90000000000000002','media_type'=>'VIDEO','thumbnail_url'=>'https://cdn.invalid/cover.png','timestamp'=>$time],
            ]]);
        }
        return Http::response($png);
    });
};
DB::beginTransaction();
try {
    $writes = [];
    DB::listen(static function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace)\s/i', $query->sql)) $writes[] = $query->sql;
    });
    $installFake();
    $exit = Artisan::call('peachy:capture-instagram-stories');
    $rows = DB::table(InstagramStoryCapture::TABLE)->get();
    mysqlStoryCheck($exit === 1 && $rows->count() === ($accounts->count()-1)*2, 'real command isolates invalid token and captures all other accounts');
    mysqlStoryCheck($rows->every(fn ($row) => (int) $accounts->firstWhere('id',$row->account_id)->workspace_id === (int) $row->workspace_id), 'real MySQL captures retain exact account/workspace ownership');
    mysqlStoryCheck($rows->every(fn ($row) => $row->image_path && Storage::disk('public')->get($row->image_path) === $png && Storage::disk('public')->getVisibility($row->image_path) === 'public'), 'all captured images and video covers contain public verified bytes');
    mysqlStoryCheck($rows->where('media_type','VIDEO')->every(fn ($row) => ! $row->video_path && str_contains($row->error,'missing_media_url')), 'missing video URLs retain covers and actionable errors');
    $calls = 0; $installFake(); Artisan::call('peachy:capture-instagram-stories');
    mysqlStoryCheck($calls === $accounts->count() && DB::table(InstagramStoryCapture::TABLE)->count() === $rows->count(), 'repeat real command makes no media requests or duplicate rows');
    $calls = 0; $installFake(true); Artisan::call('peachy:capture-instagram-stories');
    mysqlStoryCheck($calls === $accounts->count(), 'expired incomplete Stories are never downloaded');
    $copy = (array) $rows->first(); unset($copy['id']); $rejected = false;
    try { DB::table(InstagramStoryCapture::TABLE)->insert($copy); } catch (Illuminate\Database\QueryException) { $rejected = true; }
    mysqlStoryCheck($rejected, 'MySQL unique constraint rejects duplicate account and media ID');
    mysqlStoryCheck($writes && collect($writes)->every(fn ($sql) => str_contains($sql, 'peachy_ig_story_media')), 'capture writes only to the Peachy table');
    $events = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())->filter(fn ($event) => str_contains($event->command ?? '', 'peachy:capture-instagram-stories'));
    mysqlStoryCheck($events->count() === 1 && $events->first()->expression === '0 * * * *' && $events->first()->withoutOverlapping && $events->first()->runInBackground, 'real application has one hourly background capture event with overlap protection');
} finally {
    DB::rollBack();
    Storage::disk('public')->deleteDirectory('');
}
mysqlStoryCheck(DB::table(InstagramStoryCapture::TABLE)->count() === 0, 'MySQL rehearsal rolls back every capture fixture');
