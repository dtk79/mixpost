<?php
// Isolated restored Pro database only. All writes roll back; provider HTTP is fake.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): void { fwrite(STDERR, (string) $error); exit(1); });
use Illuminate\Support\Facades\{DB, Http, Bus, Event, Notification};
use Inovector\Mixpost\Models\{Account, Workspace, ImportedPost};
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\SocialProviders\Twitter\Jobs\ImportTwitterPostsJob;
use Inovector\Mixpost\SocialProviders\Twitter\TwitterProvider;
use Inovector\Mixpost\Support\SocialProviderResponse;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
Http::preventStrayRequests(); Bus::fake(); Event::fake(); Notification::fake();
class MediaIntegrationProvider extends TwitterProvider {
    public array $calls = [];
    public bool $fail = false;
    public function getTweetMedia(array $ids): SocialProviderResponse {
        $this->calls[] = $ids;
        return new SocialProviderResponse($this->fail ? SocialProviderResponseStatus::ERROR : SocialProviderResponseStatus::OK, [
            'includes' => (object) ['media' => array_map(fn ($id) => (object) [
                'media_key' => 'media-'.$id, 'type' => 'video', 'preview_image_url' => 'https://example.invalid/'.$id.'.jpg',
            ], $ids)],
        ]);
    }
}
class MediaIntegrationJob extends ImportTwitterPostsJob {
    public MediaIntegrationProvider $fixture;
    public function connectProvider(Account $account): Inovector\Mixpost\Contracts\SocialProvider { return $this->fixture; }
    public function page(array $items): void { $this->processResponse(new SocialProviderResponse(SocialProviderResponseStatus::OK, ['data' => $items])); }
}
function mediaCheck(bool $value, string $message): void { if (! $value) throw new RuntimeException($message); echo "PASS $message\n"; }
$account = Account::withoutGlobalScopes()->where('provider', 'twitter')->firstOrFail();
WorkspaceManager::setCurrent(Workspace::findOrFail($account->workspace_id));
$provider = new MediaIntegrationProvider(Illuminate\Http\Request::create('/'), '', '', '', []);
$job = new MediaIntegrationJob($account); $job->fixture = $provider;
$id = '__x_media_cost_'.bin2hex(random_bytes(6));
$item = (object) ['id' => $id, 'text' => 'short', 'note_tweet' => (object) ['text' => 'Long body'],
    'created_at' => '2026-10-20T12:00:00Z', 'attachments' => (object) ['media_keys' => ['media-'.$id]],
    'public_metrics' => (object) ['like_count' => 9, 'impression_count' => 42]];
$query = fn () => ImportedPost::where('account_id', $account->id)->where('provider_post_id', $id);
$count = DB::table('mixpost_imported_posts')->count();
DB::beginTransaction();
try {
    $job->page([$item]); $row = $query()->firstOrFail();
    mediaCheck($row->content_type === 'video' && $row->thumbnail === 'https://example.invalid/'.$id.'.jpg' && $row->text === 'Long body', 'database first import stores complete media metadata');
    $query()->update(['thumbnail' => 'imported/existing/cached.jpg']); $provider->calls = [];
    $item->note_tweet->text = 'Updated long body'; $item->public_metrics->like_count = 17;
    $job->page([$item]); $row = $query()->firstOrFail();
    mediaCheck($provider->calls === [] && $row->thumbnail === 'imported/existing/cached.jpg' && $row->content_type === 'video' && $row->text === 'Updated long body', 'database repeat import preserves media and updates text without media calls');
    mediaCheck(DB::table('mixpost_twitter_post_insights')->where('account_id', $account->id)->where('provider_post_id', $id)->where('value', 17)->exists(), 'database repeat import updates post metrics');
    $query()->update(['thumbnail' => '', 'content_type' => 'unknown']); $provider->fail = true;
    $job->page([$item]);
    mediaCheck($query()->value('thumbnail') === '', 'database metadata failure remains recoverable');
    $provider->fail = false; $job->page([$item]);
    mediaCheck($query()->value('content_type') === 'video' && $query()->value('thumbnail') !== '', 'database later refresh repairs missing media');
} finally { DB::rollBack(); }
mediaCheck(! $query()->exists() && DB::table('mixpost_imported_posts')->count() === $count, 'database fixtures rolled back with original row count preserved');
