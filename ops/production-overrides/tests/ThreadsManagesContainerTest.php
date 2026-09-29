<?php

// Run against the installed Pro application's dependencies; all provider HTTP is faked.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
});
require getenv('THREADS_CONTAINER_PATH') ?: dirname(__DIR__).'/ThreadsManagesContainer.php';

use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Support\SocialProviderResponse;

$requests = [];
Http::preventStrayRequests();
Http::fake(function ($request) use (&$requests) {
    $requests[] = $request->data();
    return Http::response(['id' => 'test-container']);
});

$provider = new class {
    use Inovector\Mixpost\SocialProviders\Threads\Concerns\ManagesContainer;
    protected string $graphUrl = 'https://graph.threads.net';
    protected string $graphVersion = 'v1.0';
    protected array $values = ['provider_id' => 'test-user'];
    public function accessToken(): string { return 'test-token'; }
    public function http(): string { return Http::class; }
    public function buildResponse($response): SocialProviderResponse {
        return new SocialProviderResponse(SocialProviderResponseStatus::OK, $response->json());
    }
    public function response($status, $data): SocialProviderResponse {
        return new SocialProviderResponse($status, $data);
    }
};

function mediaFixture(string $type, array $urls = [], int $size = 100): Media {
    $media = new class extends Media {
        public array $testUrls = [];
        public function getUrl(): string { return 'https://example.com/original'; }
        public function getConversionUrl(string $name): ?string { return $this->testUrls[$name] ?? null; }
    };
    $media->mime_type = $type;
    $media->data = [];
    $media->size = $size;
    $media->testUrls = $urls;
    return $media;
}

function check(bool $condition, string $message): void {
    if (! $condition) { throw new RuntimeException($message); }
}

$video = mediaFixture('video/mp4', ['social_video' => 'https://example.com/optimized', 'instagram_reel' => 'https://example.com/legacy'], 400 * 1024 * 1024);
foreach ([false, true] as $carousel) {
    $provider->createContainer($video, ['text' => 'Caption', 'is_carousel_item' => $carousel]);
    $request = end($requests);
    check($request['video_url'] === 'https://example.com/optimized', 'Single and carousel videos must prefer the optimized file');
    check($request['media_type'] === 'VIDEO' && $request['text'] === 'Caption' && $request['is_carousel_item'] === $carousel, 'Video payload must preserve caption and carousel flag');
}
$provider->createContainer(mediaFixture('video/mp4', ['instagram_reel' => 'https://example.com/legacy']));
check(end($requests)['video_url'] === 'https://example.com/legacy', 'Legacy conversion must remain supported');
$provider->createContainer(mediaFixture('video/mp4'));
check(end($requests)['video_url'] === 'https://example.com/original', 'Small legacy originals retain existing fallback');
$count = count($requests);
$response = $provider->createContainer(mediaFixture('video/mp4', [], 301 * 1024 * 1024));
check($response->hasError() && count($requests) === $count, 'Oversized unoptimized video must fail before provider HTTP');
$image = mediaFixture('image/jpeg', ['social_video' => 'https://example.com/unused']);
$image->data = ['alt_text' => 'An image'];
$provider->createContainer($image);
check(end($requests)['image_url'] === 'https://example.com/original' && end($requests)['alt_text'] === 'An image', 'Images and alt text must be preserved');
$provider->createContainer(null, ['text' => 'Text only']);
check(end($requests) === ['text' => 'Text only', 'media_type' => 'TEXT'], 'Text-only posts must remain unchanged');
$provider->createContainer(null, ['media_type' => 'CAROUSEL', 'children' => 'one,two']);
check(end($requests)['media_type'] === 'CAROUSEL' && end($requests)['children'] === 'one,two', 'Carousel parent must remain unchanged');
echo "Threads container request tests passed (8 cases)\n";
