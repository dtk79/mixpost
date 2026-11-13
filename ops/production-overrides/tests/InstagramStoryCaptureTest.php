<?php

// No application boot, production DB, credentials, network or bucket access.
// Tests use the candidate's actual Pro models, token cast and Meta response
// builder, real Laravel HTTP/storage fakes, and a table-restricted DB double.
$loader = require (getenv('PEACHY_STORIES_AUTOLOAD') ?: '/var/www/html/vendor/autoload.php');
if ($pro = getenv('PEACHY_STORIES_PRO_ROOT')) {
    $loader->setPsr4('Inovector\\Mixpost\\', [$pro.'/src']);
}
$source = getenv('PEACHY_STORIES_SOURCE');
require_once $source ? $source.'/InstagramStoryCapture.php' : '/var/www/html/app/Support/InstagramStoryCapture.php';
require_once $source ? $source.'/CaptureInstagramStories.php' : '/var/www/html/app/Console/Commands/CaptureInstagramStories.php';

use App\Console\Commands\CaptureInstagramStories;
use App\Support\InstagramStoryCapture;
use Illuminate\Config\Repository;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inovector\Mixpost\Facades\SocialProviderManager;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Workspace;
use Inovector\Mixpost\SocialProviders\Instagram\InstagramStandaloneProvider;
use Inovector\Mixpost\SocialProviders\Meta\InstagramProvider;
use Inovector\Mixpost\Support\ProviderRateLimit;

function storyCheck(bool $ok, string $message): void {
    if (! $ok) throw new RuntimeException($message);
    echo "PASS $message\n";
}

class StoryDatabase {
    public array $rows = [];
    public function table(string $name): StoryQuery {
        if ($name !== InstagramStoryCapture::TABLE) throw new LogicException('Attempted access to Mixpost table');
        return new StoryQuery($this);
    }
}
class StoryQuery {
    private array $where = [];
    public function __construct(private StoryDatabase $db) {}
    public function where(string $key, mixed $value): self { $this->where[$key] = $value; return $this; }
    public function first(): ?object {
        foreach ($this->db->rows as $row) {
            foreach ($this->where as $key => $value) if ($row->$key != $value) continue 2;
            return clone $row;
        }
        return null;
    }
    public function insert(array $value): bool {
        $key = $value['account_id'].':'.$value['provider_post_id'];
        if (isset($this->db->rows[$key])) throw new LogicException('Duplicate story');
        $this->db->rows[$key] = (object) ($value + array_fill_keys(['image_path','video_path','mime_type','width','height','captured_at','error'], null));
        return true;
    }
    public function update(array $value): int {
        $row = $this->first();
        if (! $row) return 0;
        $this->db->rows[$row->account_id.':'.$row->provider_post_id] = (object) array_replace((array) $row, $value);
        return 1;
    }
}
// Retain the actual candidate's endpoint resolution, token handling and
// buildResponse. Avoid loading service credentials from the real database.
class StoryFacebookProvider extends InstagramProvider {
    public function __construct(Illuminate\Http\Request $request, string $clientId, string $clientSecret, string $redirectUrl, array $values = []) { $this->values = $values; $this->apiVersion = 'v27.0'; }
}
class StoryStandaloneProvider extends InstagramStandaloneProvider {
    public function __construct(Illuminate\Http\Request $request, string $clientId, string $clientSecret, string $redirectUrl, array $values = []) { $this->values = $values; }
}

$root = sys_get_temp_dir().'/peachy-story-tests-'.bin2hex(random_bytes(8));
mkdir($root, 0700, true);
$app = new Application($root);
$app->instance('config', new Repository([
    'app' => ['key' => 'base64:'.base64_encode(str_repeat('t',32)), 'cipher' => 'AES-256-CBC'],
    'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array']]],
    'filesystems' => ['default' => 'story-test', 'disks' => ['story-test' => ['driver' => 'local', 'root' => $root.'/media']]],
    'mixpost' => ['disk' => 'story-test'],
    'peachy-stories' => ['enabled' => true, 'download_videos' => true, 'max_video_bytes' => 104857600, 'max_image_bytes' => 20971520],
]));
$app->instance('events', new Dispatcher($app));
Facade::setFacadeApplication($app);
$app->register(Illuminate\Filesystem\FilesystemServiceProvider::class);
$app->register(Illuminate\Cache\CacheServiceProvider::class);
$app->register(Illuminate\Encryption\EncryptionServiceProvider::class);
$logs = new class {
    public array $messages = [];
    public function __call($name, $args) { $this->messages[] = [$name, $args]; }
};
Log::swap($logs);
$db = new StoryDatabase;
DB::swap($db);
SocialProviderManager::swap(new class {
    public function connect(string $provider, array $values): InstagramProvider {
        return $provider === 'instagram' ? new StoryFacebookProvider(new Illuminate\Http\Request, '', '', '', $values) : new StoryStandaloneProvider(new Illuminate\Http\Request, '', '', '', $values);
    }
});
$workspace = new Workspace;
$workspace->setRawAttributes(['id' => 12, 'uuid' => 'workspace-12']);
$account = new Account;
$account->forceFill(['id' => 109, 'workspace_id' => 12, 'uuid' => 'account-109', 'provider' => 'instagram',
    'provider_id' => '17840000000000001', 'data' => [], 'access_token' => ['access_token' => 'test-secret-never-log']]);
$capture = new InstagramStoryCapture;
Carbon::setTestNow('2026-11-13T20:00:00Z');
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j5WQAAAAASUVORK5CYII=');
$mp4 = hex2bin('000000186674797069736f6d0000020069736f6d6d703432').'test-video';
$calls = [];
$page = [];
$graphError = null;
$headers = [];
$downloadFailure = false;
$large = false;
$fake = function () use (&$calls, &$page, &$graphError, &$headers, &$downloadFailure, &$large, $png, $mp4): void {
    $app = Facade::getFacadeApplication();
    $app->forgetInstance(Illuminate\Http\Client\Factory::class);
    Http::clearResolvedInstance(Illuminate\Http\Client\Factory::class);
    Http::preventStrayRequests();
    Http::fake(function ($request) use (&$calls, &$page, &$graphError, &$headers, &$downloadFailure, &$large, $png, $mp4) {
        $calls[] = ['url' => $request->url(), 'authorization' => $request->header('Authorization')];
        if (str_contains($request->url(), '/stories')) {
            if ($graphError) return Http::response(['error' => $graphError], $graphError['code'] === 190 ? 401 : 400, $headers);
            $data = isset($request['after']) ? ($page[$request['after']] ?? ['data' => []]) : $page['first'];
            return Http::response($data, 200, $headers);
        }
        if ($downloadFailure) throw new RuntimeException('signed URL?access_token=test-secret-never-log');
        return Http::response(str_ends_with($request->url(), '.mp4') ? $mp4 : $png, 200,
            $large ? ['Content-Length' => 999999999] : []);
    });
};
$story = fn ($id, $type = 'IMAGE') => ['id' => (string) $id, 'media_type' => $type,
    'media_url' => 'https://cdn.example/story.'.($type === 'VIDEO' ? 'mp4' : 'png'),
    'thumbnail_url' => 'https://cdn.example/cover.png', 'permalink' => 'https://instagram.com/stories/test/'.$id,
    'timestamp' => now('UTC')->subHour()->toIso8601String()];
$run = function (array $stories) use (&$page, $fake, $capture, $account, $workspace) {
    $page = ['first' => ['data' => $stories]];
    $fake();
    return $capture->captureAccount($account, $workspace, microtime(true) + 60);
};
$row = fn ($id) => $db->rows['109:'.$id] ?? null;
try {
    Storage::fake('story-test');
    $result = $run([$story(1)]);
    storyCheck($result['captured'] === 1 && $row(1)->image_path === 'peachy/stories/workspace-12/account-109/1.png', 'image Story stored at deterministic bucket-relative path');
    storyCheck(Storage::disk('story-test')->get($row(1)->image_path) === $png && Storage::disk('story-test')->getVisibility($row(1)->image_path) === 'public', 'stored image is public and has exact downloaded bytes');
    storyCheck($row(1)->width === 1 && $row(1)->height === 1 && $row(1)->mime_type === 'image/png' && $row(1)->captured_at !== null, 'image dimensions MIME and capture timestamp recorded');
    storyCheck($calls[0]['authorization'] === ['Bearer test-secret-never-log'] && ! str_contains($calls[0]['url'], 'test-secret'), 'Graph uses current Mixpost token without credentials in URL');
    $calls = [];
    $result = $run([$story(1)]);
    storyCheck($result['skipped'] === 1 && count($calls) === 1 && count($db->rows) === 1, 'repeat capture makes no media requests or duplicate rows');
    $result = $run([$story(2, 'VIDEO')]);
    storyCheck($result['captured'] === 1 && $row(2)->image_path && $row(2)->video_path && Storage::disk('story-test')->get($row(2)->video_path) === $mp4, 'video Story stores cover and optional video');
    $missing = $story(3, 'VIDEO'); unset($missing['media_url']);
    $result = $run([$missing]);
    storyCheck($result['captured'] === 1 && $result['errors'] === 1 && $row(3)->image_path && ! $row(3)->video_path && str_contains($row(3)->error, 'missing_media_url'), 'missing video URL still preserves cover and records failure');
    $result = $run([$story(3, 'VIDEO')]);
    storyCheck($result['captured'] === 1 && $row(3)->video_path && $row(3)->error === null, 'partial capture recovers missing video while Story is live');
    $missing = $story(4); unset($missing['media_url']);
    $run([$missing]);
    storyCheck(! $row(4)->image_path && str_contains($row(4)->error, 'missing_media_url'), 'missing image URL records a nullable image and sanitized error');
    $calls = []; $expired = $story(4); $expired['timestamp'] = now('UTC')->subDay()->toIso8601String();
    $result = $run([$expired]);
    storyCheck($result['skipped'] === 1 && count($calls) === 1 && ! $row(4)->image_path, 'expired error row is never retried');
    config(['peachy-stories.download_videos' => false]);
    $calls = []; $run([$story(5, 'VIDEO')]);
    storyCheck($row(5)->image_path && ! $row(5)->video_path && count($calls) === 2 && ! $row(5)->error, 'video download flag preserves cover without requesting video');
    config(['peachy-stories.download_videos' => true, 'peachy-stories.max_video_bytes' => 8]);
    $run([$story(6, 'VIDEO')]);
    storyCheck($row(6)->image_path && ! $row(6)->video_path && str_contains($row(6)->error, 'size_cap_exceeded'), 'streamed video is capped without losing its cover');
    config(['peachy-stories.max_video_bytes' => 104857600]);
    $large = true; $run([$story(7)]); $large = false;
    storyCheck(! $row(7)->image_path && str_contains($row(7)->error, 'size_cap_exceeded'), 'oversized declared length is rejected before storage');
    $downloadFailure = true; $run([$story(8)]); $downloadFailure = false;
    storyCheck(! str_contains($row(8)->error, 'test-secret') && $row(8)->error === 'image:story_transfer_failed', 'transport exception secrets are never persisted');
    $calls = []; $run([$story(8)]);
    storyCheck($row(8)->image_path && ! $row(8)->error && count($calls) === 2, 'failed download retries successfully without duplicate metadata');
    $page = ['first' => ['data' => [$story(9)], 'paging' => ['next' => 'https://untrusted.invalid/?access_token=do-not-follow', 'cursors' => ['after' => 'next-page']]], 'next-page' => ['data' => [$story(10)]]];
    $calls = []; $fake(); $result = $capture->captureAccount($account, $workspace, microtime(true)+60);
    storyCheck($row(9)->image_path && $row(10)->image_path && count($calls) === 4 && ! str_contains(json_encode($calls), 'untrusted'), 'all pages captured using safe cursors and configured provider endpoint');
    $graphError = ['code' => 190, 'message' => 'expired token test-secret-never-log'];
    $result = $run([]); $graphError = null;
    storyCheck($result['status'] === 'unauthorized' && $result['errors'] === 1 && ! str_contains(json_encode($result), 'test-secret'), 'invalid token is reported safely with no Mixpost account writes');
    $graphError = ['code' => 4, 'message' => 'rate limited'];
    $result = $run([]); $graphError = null;
    storyCheck($result['status'] === 'rate_limited' && ProviderRateLimit::retryAfter(109, 'instagram') > 0, 'Graph rate limit enters shared Mixpost cooldown');
    $calls = []; $result = $run([]);
    storyCheck($result['status'] === 'rate_limited' && ! $calls, 'existing provider cooldown prevents further requests');
    $other = clone $account; $other->id = 200;
    $capture->captureAccount($other, $workspace, microtime(true)+60);
    storyCheck(! $calls, 'app-level Graph limit also protects other connected accounts');
    Cache::flush();
    $graphError = ['code' => 10, 'message' => 'needs permission']; $result = $run([]); $graphError = null;
    storyCheck($result['status'] === 'permission_missing' && $result['errors'] === 1, 'missing permission is explicit and requires operator intervention');
    $account->provider = 'instagram_standalone';
    $calls = []; $run([]);
    storyCheck(str_starts_with($calls[0]['url'], 'https://graph.instagram.com/'), 'standalone login uses its own Mixpost Graph domain');
    $graphError = ['code' => 100, 'message' => 'Tried accessing nonexisting field (stories) on node type'];
    $before = count($logs->messages); $result = $run([]); $run([]); $graphError = null;
    storyCheck($result['status'] === 'unsupported' && count($logs->messages) === $before + 1, 'unavailable standalone Stories edge is skipped and logged once');
    $account->provider = 'instagram';
    $lock = Cache::lock('peachy:instagram-story-capture', 3600); $lock->get();
    $command = new CaptureInstagramStories;
    $command->setLaravel($app);
    $command->setOutput(new Illuminate\Console\OutputStyle(new Symfony\Component\Console\Input\ArrayInput([]), new Symfony\Component\Console\Output\BufferedOutput));
    storyCheck($command->handle($capture) === 0, 'manual command shares overlap lock and exits without reading accounts');
    $lock->release();
    $originalDisk = Storage::disk('story-test');
    Storage::set('story-test', new class($originalDisk->getDriver(), $originalDisk->getAdapter(), $originalDisk->getConfig()) extends Illuminate\Filesystem\FilesystemAdapter {
        public function put($path, $contents, $options = []) { return false; }
    });
    $run([$story(11)]);
    storyCheck(! $row(11)->image_path && $row(11)->error === 'image:story_storage_write_failed', 'failed object write never publishes a database pointer');
    Storage::set('story-test', $originalDisk); $run([$story(11)]);
    storyCheck($row(11)->image_path && ! $row(11)->error, 'storage failure recovers on the next live capture');
    $page = ['first' => ['data' => [$story(12)]]];
    Http::swap(new Illuminate\Http\Client\Factory);
    Http::preventStrayRequests();
    Http::fake(fn ($request) => str_contains($request->url(), '/stories') ? Http::response($page['first']) : Http::response('<html>not media</html>'));
    $capture->captureAccount($account, $workspace, microtime(true)+60);
    storyCheck(! $row(12)->image_path && str_contains($row(12)->error, 'invalid_media_content'), 'successful HTTP status with non-media content is rejected');
    storyCheck(! str_contains(json_encode($logs->messages), 'test-secret') && ! str_contains(json_encode($db->rows), 'test-secret'), 'tokens and signed URLs never enter logs or capture table');
} finally {
    Carbon::setTestNow();
    (new Illuminate\Filesystem\Filesystem)->deleteDirectory($root);
}
