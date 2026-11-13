<?php

// Local SQLite integration, using a private Pro export and compatible Composer
// dependencies. The release rehearsal must repeat these DB cases on restored
// isolated MySQL. No production application boot or external HTTP/storage.
require __DIR__.'/InstagramStoryCaptureTest.php';

use App\Console\Commands\CaptureInstagramStories;
use App\Support\InstagramStoryCapture;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inovector\Mixpost\Facades\ServiceManager;
use Inovector\Mixpost\Facades\SocialProviderManager;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Models\Workspace;
use Inovector\Mixpost\SocialProviders\Meta\InstagramProvider;

mkdir($root, 0700, true);
Carbon::setTestNow('2026-11-13T20:00:00Z');
config(['database.default' => 'sqlite', 'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
$app->register(Illuminate\Database\DatabaseServiceProvider::class);
DB::clearResolvedInstance('db');
Model::setConnectionResolver($app['db']);
Model::setEventDispatcher($app['events']);
$app->instance('request', new Illuminate\Http\Request);
WorkspaceManager::swap(new class {
    public ?Workspace $workspace = null;
    public function current(): ?Workspace { return $this->workspace; }
    public function setCurrent(Workspace $workspace): void { $this->workspace = $workspace; }
    public function forgetCurrent(): void { $this->workspace = null; }
});
ServiceManager::swap(new class { public function isActive($name): bool { return true; } });
SocialProviderManager::swap(new class {
    public function providers(): array { return ['instagram' => StoryFacebookProvider::class, 'instagram_standalone' => StoryStandaloneProvider::class]; }
    public function connect(string $provider, array $values): InstagramProvider {
        return new StoryFacebookProvider(new Illuminate\Http\Request, '', '', '', $values);
    }
});
try {
    Schema::create('mixpost_workspaces', function (Blueprint $table) {
        $table->id(); $table->string('uuid'); $table->string('name'); $table->timestamps();
    });
    Schema::create('mixpost_accounts', function (Blueprint $table) {
        $table->id(); $table->unsignedBigInteger('workspace_id'); $table->string('uuid');
        $table->string('provider'); $table->string('provider_id'); $table->boolean('authorized');
        $table->text('access_token'); $table->text('data'); $table->string('name')->nullable();
        $table->string('username')->nullable(); $table->timestamps();
    });
    foreach ([1, 12] as $id) DB::table('mixpost_workspaces')->insert(['id' => $id, 'uuid' => 'workspace-'.$id, 'name' => 'Test '.$id]);
    foreach ([[112,1,true],[113,1,true],[109,12,true],[114,12,false]] as [$id,$wid,$auth]) {
        DB::table('mixpost_accounts')->insert(['id' => $id, 'workspace_id' => $wid, 'uuid' => 'account-'.$id,
            'provider' => 'instagram', 'provider_id' => (string) $id, 'authorized' => $auth,
            'access_token' => Crypt::encryptString(json_encode(['access_token' => 'test-secret-never-log'])), 'data' => '{}']);
    }
    $before = json_encode([DB::table('mixpost_workspaces')->get(), DB::table('mixpost_accounts')->get()]);
    $migration = require ($source ?: '/var/www/html/database/migrations').'/2026_11_13_000000_create_peachy_ig_story_media.php';
    $migration->up();
    storyCheck(Schema::hasColumns(InstagramStoryCapture::TABLE, ['workspace_id','provider_post_id','image_path','video_path','error','posted_at']), 'integration migration creates Annex table contract');
    Storage::fake('story-test'); Cache::flush();
    $calls = [];
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(function ($request) use (&$calls, $png) {
        $calls[] = $request->url();
        if (str_contains($request->url(), '/112/stories')) throw new RuntimeException('failed account with token test-secret-never-log');
        if (str_contains($request->url(), '/stories')) return Http::response(['data' => [
            ['id' => '100', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.example/image.png', 'timestamp' => now('UTC')->subHour()->toIso8601String()],
        ]]);
        return Http::response($png);
    });
    $command = new CaptureInstagramStories;
    $command->setLaravel($app);
    $command->setOutput(new Illuminate\Console\OutputStyle(new Symfony\Component\Console\Input\ArrayInput([]), new Symfony\Component\Console\Output\BufferedOutput));
    storyCheck($command->handle(new InstagramStoryCapture) === 1 && DB::table(InstagramStoryCapture::TABLE)->count() === 2,
        'integration failing account does not stop same-workspace or later-workspace accounts');
    storyCheck(DB::table(InstagramStoryCapture::TABLE)->where('account_id',109)->value('workspace_id') === 12
        && DB::table(InstagramStoryCapture::TABLE)->where('account_id',113)->value('workspace_id') === 1
        && ! str_contains(json_encode($calls), '/114/'), 'integration workspace ownership and unauthorized-account exclusion hold');
    storyCheck(json_encode([DB::table('mixpost_workspaces')->get(), DB::table('mixpost_accounts')->get()]) === $before,
        'integration original Mixpost rows including encrypted tokens remain byte-for-byte unchanged');
    $duplicate = (array) DB::table(InstagramStoryCapture::TABLE)->first(); unset($duplicate['id']);
    $rejected = false;
    try { DB::table(InstagramStoryCapture::TABLE)->insert($duplicate); } catch (Illuminate\Database\QueryException) { $rejected = true; }
    storyCheck($rejected, 'integration database rejects duplicate account and Story IDs');
    require_once $source.'/AppServiceProvider.php';
    (new App\Providers\AppServiceProvider($app))->boot();
    $schedule = $app->make(Illuminate\Console\Scheduling\Schedule::class);
    $events = array_filter($schedule->events(), fn ($event) => str_contains($event->command ?? '', 'peachy:capture-instagram-stories'));
    $event = reset($events);
    storyCheck(count($events) === 1 && $event->expression === '0 * * * *' && $event->withoutOverlapping && $event->runInBackground,
        'integration application provider registers one hourly schedule with overlap protection');
    $artisan = new Illuminate\Console\Application($app, $app['events'], 'test');
    storyCheck($artisan->has('peachy:capture-instagram-stories'), 'integration application provider registers Artisan command');
    $requests = 0;
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::fake(function () use (&$requests) { $requests++; return Http::response(['error' => ['code' => 10, 'message' => 'permission required']], 403); });
    storyCheck($command->handle(new InstagramStoryCapture) === 1 && $requests === 1,
        'integration missing permission stops the command without trying other accounts');
} finally {
    Carbon::setTestNow();
    (new Illuminate\Filesystem\Filesystem)->deleteDirectory($root);
}
