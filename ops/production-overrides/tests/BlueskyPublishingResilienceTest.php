<?php
// Real candidate Helpers, publishing trait, and failure callback; fake only
// HTTP, temporary downloads, and persistence. No app bootstrap or live services.
namespace Inovector\Mixpost\Support {
    final class TemporaryFile
    {
        public static array $urls = [];
        public static int $cleanups = 0;
        public static ?\Exception $error = null;
        public static function make(): self { return new self; }
        public function fromUrl(string $url): self {
            self::$urls[] = $url;
            if (self::$error) { throw self::$error; }
            return $this;
        }
        public function directory(): object {
            return new class {
                public function delete(): void { TemporaryFile::$cleanups++; }
            };
        }
    }
}
namespace {
    require '/var/www/html/vendor/autoload.php';
    use Illuminate\Container\Container;
    use Illuminate\Events\Dispatcher;
    use Illuminate\Http\Client\ConnectionException;
    use Illuminate\Http\Client\Factory;
    use Illuminate\Support\Collection;
    use Illuminate\Support\Facades\Facade;
    use Illuminate\Support\Facades\Http;
    use Inovector\Mixpost\Enums\PostAccountStatus;
    use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
    use Inovector\Mixpost\Events\Post\PostPublishedFailed;
    use Inovector\Mixpost\Models\Account;
    use Inovector\Mixpost\Models\Post;
    use Inovector\Mixpost\Support\SocialProviderResponse;
    use Inovector\Mixpost\Support\TemporaryFile;

    $app = new Container;
    Container::setInstance($app);
    Facade::setFacadeApplication($app);
    $events = new Dispatcher($app);
    $app->instance('events', $events);
    $failures = 0;
    $events->listen(PostPublishedFailed::class, function () use (&$failures) { $failures++; });
    $root = getenv('BLUESKY_SOURCE_ROOT') ?: '/var/www/html/vendor/inovector/mixpost-pro-team';
    require $root.'/src/SocialProviders/Bluesky/Helpers.php';
    require $root.'/src/SocialProviders/Bluesky/Concerns/ManagesPost.php';
    require $root.'/src/Jobs/AccountPublishPostJob.php';
    set_exception_handler(function (\Throwable $error): void {
        fwrite(STDERR, get_class($error).': '.$error->getMessage()."\n".$error->getTraceAsString()."\n");
        exit(1);
    });
    $provider = new class {
        use \Inovector\Mixpost\SocialProviders\Bluesky\Concerns\ManagesPost;
        public ?\Exception $uploadError = null;
        public bool $rejectUpload = false;
        public function refreshTokenIfNeeded(): ?SocialProviderResponse { return null; }
        public function getServer(): string { return 'https://pds.example.test'; }
        public function getDid(): string { return 'did:plc:fixture'; }
        public function client(): \Illuminate\Http\Client\PendingRequest { return Http::baseUrl('https://pds.example.test/xrpc/'); }
        public function uploadBlob($file): SocialProviderResponse {
            if ($this->uploadError) { throw $this->uploadError; }
            return new SocialProviderResponse($this->rejectUpload ? SocialProviderResponseStatus::ERROR : SocialProviderResponseStatus::OK,
                ['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'image-cid']]]);
        }
        public function response($status, $data): SocialProviderResponse { return new SocialProviderResponse($status, $data); }
        public function buildResponse($response, $callback = null): SocialProviderResponse {
            return new SocialProviderResponse($response->successful() ? SocialProviderResponseStatus::OK : SocialProviderResponseStatus::ERROR,
                $response->successful() && $callback ? $callback($response->json()) : $response->json());
        }
    };
    $requests = [];
    $checks = 0;
    function check(bool $value, string $message): void {
        if (! $value) { throw new \RuntimeException($message); }
    }
    function test(string $name, callable $callback): void {
        global $checks;
        $callback(); $checks++; echo "PASS $name\n";
    }
    function fake(?string $image = null, int $handleStatus = 200, int $publishStatus = 200, bool $disconnect = false): void {
        global $provider, $requests;
        $requests = [];
        $provider->uploadError = null; $provider->rejectUpload = false;
        TemporaryFile::$urls = []; TemporaryFile::$cleanups = 0; TemporaryFile::$error = null;
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($image, $handleStatus, $publishStatus, $disconnect) {
            global $requests;
            $requests[] = ['method' => $request->method(), 'url' => $request->url(), 'data' => $request->data()];
            if (str_contains($request->url(), 'resolveHandle')) {
                if ($disconnect) { throw new ConnectionException('offline'); }
                $status = str_contains($request->url(), 'good.bsky.social') ? 200 : $handleStatus;
                return Http::response($status === 200 ? ['did' => 'did:plc:good'] : ['error' => 'InvalidRequest', 'message' => 'Unable to resolve handle'], $status);
            }
            if (str_ends_with($request->url(), 'createRecord')) {
                return Http::response($publishStatus === 200 ? ['uri' => 'at://did:plc:fixture/app.bsky.feed.post/new', 'cid' => 'post-cid'] : ['error' => 'Rejected'], $publishStatus);
            }
            if (str_starts_with($request->url(), 'https://site.example.test/')) {
                return Http::response('<title>Preview title</title>'.($image === null ? '' : '<meta property="og:image" content="'.htmlspecialchars($image, ENT_QUOTES).'">'));
            }
            throw new \RuntimeException('Unexpected HTTP request: '.$request->url());
        });
    }
    function publish(string $text = 'Caption', array $params = []): SocialProviderResponse {
        global $provider;
        return $provider->publishPost($text, new Collection, $params);
    }
    function record(): array {
        global $requests;
        $posts = array_values(array_filter($requests, fn ($r) => str_ends_with($r['url'], 'createRecord')));
        check(count($posts) === 1, 'Expected exactly one record creation');
        return $posts[0]['data']['record'];
    }
    function card(): array { return record()['embed']['external']; }

    test('valid mention keeps its facet', function () { fake(); check(! publish('Hi @good.bsky.social')->hasError(), 'publish failed'); check(count(record()['facets']) === 1, 'missing facet'); });
    test('unknown handle publishes as plain text', function () { fake(handleStatus: 400); check(! publish('Hi @missing.bsky.social')->hasError(), 'publish failed'); check(record()['text'] === 'Hi @missing.bsky.social' && record()['facets'] === [], 'text lost or bad facet'); });
    test('identity server failure is optional', function () { fake(handleStatus: 503); check(! publish('Hi @missing.bsky.social')->hasError(), 'server error aborted publication'); });
    test('identity connection failure is optional', function () { fake(disconnect: true); check(! publish('Hi @missing.bsky.social')->hasError(), 'connection error aborted publication'); });
    test('valid mentions survive another unresolved handle', function () { fake(handleStatus: 400); publish('Hi @missing.bsky.social and @good.bsky.social'); check(count(record()['facets']) === 1 && record()['facets'][0]['features'][0]['did'] === 'did:plc:good', 'valid mention lost'); });
    test('root relative image resolves against linked site', function () { fake('/signup/img/logo.png'); publish(params: ['url' => 'https://site.example.test/signup/join']); check(TemporaryFile::$urls === ['https://site.example.test/signup/img/logo.png'] && isset(card()['thumb']), 'relative image failed'); });
    test('path relative image resolves and preserves escaped query', function () { fake('../img/logo.png?a=1&b=2'); publish(params: ['url' => 'https://site.example.test/signup/join']); check(TemporaryFile::$urls === ['https://site.example.test/img/logo.png?a=1&b=2'], 'relative path/query changed'); });
    test('protocol relative image uses source scheme', function () { fake('//cdn.example.test/logo.png'); publish(params: ['url' => 'https://site.example.test/join']); check(TemporaryFile::$urls === ['https://cdn.example.test/logo.png'], 'protocol relative URL failed'); });
    test('non HTTP image is skipped', function () { fake('data:image/png;base64,AAAA'); check(! publish(params: ['url' => 'https://site.example.test/join'])->hasError(), 'unsupported image blocked publication'); check(TemporaryFile::$urls === [] && ! isset(card()['thumb']), 'unsupported image downloaded'); });
    test('failed image download retains card and cleans temporary directory', function () { fake('/logo.svg'); TemporaryFile::$error = new \RuntimeException('unsupported image'); check(! publish(params: ['url' => 'https://site.example.test/join'])->hasError(), 'download failure escaped'); check(card()['title'] === 'Preview title' && ! isset(card()['thumb']) && TemporaryFile::$cleanups === 1, 'card/cleanup lost'); });
    test('rejected thumbnail upload retains card', function () { global $provider; fake('/logo.svg'); $provider->rejectUpload = true; check(! publish(params: ['url' => 'https://site.example.test/join'])->hasError() && ! isset(card()['thumb']), 'rejected thumb blocked publication'); });
    test('thumbnail upload exception retains card and cleans directory', function () { global $provider; fake('/logo.png'); $provider->uploadError = new ConnectionException('upload unavailable'); check(! publish(params: ['url' => 'https://site.example.test/join'])->hasError() && TemporaryFile::$cleanups === 1 && ! isset(card()['thumb']), 'upload failure escaped/leaked'); });
    test('card without image publishes', function () { fake(); check(! publish(params: ['url' => 'https://site.example.test/join'])->hasError() && card()['title'] === 'Preview title', 'missing image blocked card'); });
    test('actual record rejection remains an error', function () { fake('/logo.png', publishStatus: 400); check(publish(params: ['url' => 'https://site.example.test/join'])->hasError(), 'record rejection swallowed'); record(); });

    class MemoryPost extends Post {
        public PostAccountStatus $outcome = PostAccountStatus::PUBLISHED;
        public array $ids = ['at://already-live'];
        public array $errors = [];
        public function accountPublishStatus(Account $account): PostAccountStatus { return $this->outcome; }
        public function publishedProviderPostIds(Account $account): array { return $this->ids; }
        public function insertErrors(Account $account, $errors): void { $this->errors = $errors; $this->outcome = PostAccountStatus::FAILED; }
    }
    function jobFixture(array $items): array {
        $account = new Account; $account->id = 90; $account->provider = 'bluesky';
        $post = new MemoryPost;
        $post->setRelation('versions', new Collection([(object) ['account_id' => 90, 'is_original' => true, 'content' => $items]]));
        $job = new class extends \Inovector\Mixpost\Jobs\AccountPublishPostJob { public function __construct() {} };
        $job->account = $account; $job->post = $post;
        return [$job, $post];
    }
    $item = ['body' => '<div>Caption</div>', 'media' => [], 'url' => null];
    test('partial thread is failed with IDs preserved and failure event emitted', function () use ($item) {
        global $failures; [$job, $post] = jobFixture([$item, $item]); $before = $failures;
        $job->failed(new \RuntimeException('reply failed'));
        check($post->outcome === PostAccountStatus::FAILED && $post->ids === ['at://already-live'] && str_contains($post->errors[0], 'thread is live') && $failures === $before + 1, 'partial failure hidden or live ID lost');
    });
    test('completed thread keeps successful outcome', function () use ($item) { [$job, $post] = jobFixture([$item, $item]); $post->ids[] = 'at://reply'; $job->failed(new \RuntimeException); check($post->outcome === PostAccountStatus::PUBLISHED && ! $post->errors, 'completed thread overwritten'); });
    test('empty additional editor items do not imply missing replies', function () use ($item) { [$job, $post] = jobFixture([$item, ['body' => '<div></div>', 'media' => [], 'url' => null]]); $job->failed(new \RuntimeException); check($post->outcome === PostAccountStatus::PUBLISHED && ! $post->errors, 'empty editor item counted as reply'); });
    test('unpublished failure does not claim video preparation is at fault', function () use ($item) { [$job, $post] = jobFixture([$item]); $post->ids = []; $post->outcome = PostAccountStatus::PUBLISHING; $job->failed(new \RuntimeException); check($post->outcome === PostAccountStatus::FAILED && ! str_contains($post->errors[0], 'video preparation'), 'misleading video error'); });
    echo "Bluesky publishing resilience passed ($checks checks)\n";
}
