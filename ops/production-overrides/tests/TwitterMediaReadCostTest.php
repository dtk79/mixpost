<?php
// Exact Pro provider/job code, fake only persistence, account context and transport.
// No app bootstrap, database, production environment, storage or external network.
namespace Inovector\Mixpost\Models {
    class ImportedPost {
        public static array $rows = [];
        public static function query(): object {
            return new class {
                private array $filters = [];
                public function where($key, $value): self { $this->filters[$key] = [$value]; return $this; }
                public function whereIn($key, $values): self { $this->filters[$key] = $values; return $this; }
                public function get($columns): \Illuminate\Support\Collection {
                    return collect(ImportedPost::$rows)->filter(function ($row) {
                        foreach ($this->filters as $key => $values) if (! in_array($row->$key, $values)) return false;
                        return true;
                    });
                }
            };
        }
        public static function upsert($rows, $identity, $updates): void {
            foreach ($rows as $row) {
                $key = $row['workspace_id'].':'.$row['account_id'].':'.$row['provider_post_id'];
                if (! isset(self::$rows[$key])) self::$rows[$key] = (object) $row;
                else foreach ($updates as $field) self::$rows[$key]->$field = $row[$field];
            }
        }
    }
}
namespace Inovector\Mixpost\Facades {
    class WorkspaceManager { public static function current(): object { return (object) ['id' => 12]; } }
}
namespace Inovector\Mixpost\SocialProviders\Twitter\Models {
    class TwitterPostInsight {
        public static array $rows = [];
        public static function upsert($rows, $identity, $updates): void { self::$rows = $rows; }
    }
    class TwitterPostInsightHistory extends TwitterPostInsight {}
}
namespace {
    require '/var/www/html/vendor/autoload.php';
    use Abraham\TwitterOAuth\TwitterOAuth;
    use Inovector\Mixpost\Models\{Account, ImportedPost};
    use Inovector\Mixpost\SocialProviders\Twitter\Jobs\ImportTwitterPostsJob;
    use Inovector\Mixpost\SocialProviders\Twitter\TwitterProvider;
    use Inovector\Mixpost\SocialProviders\Twitter\Models\TwitterPostInsight;
    use Inovector\Mixpost\Support\SocialProviderResponse;
    use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
    use Illuminate\Contracts\Queue\ShouldQueue;
    use Illuminate\Support\Facades\{Facade, Log};
    use Illuminate\Container\Container;

    $app = new Container;
    Container::setInstance($app); Facade::setFacadeApplication($app);
    $app->instance('config', new Illuminate\Config\Repository(['mixpost' => ['queue_name_prefix' => 'fixture']]));
    $app->instance('log', new class { public function warning($message, $context = []): void {} });
    set_exception_handler(function (Throwable $error): void { fwrite(STDERR, (string) $error); exit(1); });

    class CostConnection extends TwitterOAuth {
        public array $calls = [];
        public array $fixtures = [];
        public function __construct() {}
        public function get(string $path, array $parameters = []) {
            $this->calls[] = [$path, $parameters];
            $result = array_shift($this->fixtures) ?? (object) ['data' => []];
            if ($result instanceof Throwable) throw $result;
            return $result;
        }
    }
    class CostProvider extends TwitterProvider {
        public function buildResponse($response, ?Closure $okResult = null): SocialProviderResponse {
            return new SocialProviderResponse(isset($response->error) ? SocialProviderResponseStatus::ERROR : SocialProviderResponseStatus::OK,
                $okResult && ! isset($response->error) ? $okResult() : []);
        }
        public function timelineParams(): array { return $this->twitterTimelineParams('next', '2026-10-01T00:00:00Z', '2026-10-20T00:00:00Z', false); }
    }
    class CostJob extends ImportTwitterPostsJob {
        public CostProvider $fixture;
        public ?ShouldQueue $next = null;
        public function connectProvider(Account $account): Inovector\Mixpost\Contracts\SocialProvider { return $this->fixture; }
        public function page(array $items, ?object $meta = null): void {
            $this->processResponse(new SocialProviderResponse(SocialProviderResponseStatus::OK, ['data' => $items, 'meta' => $meta]));
        }
        protected function dispatchOrAddToBatch(ShouldQueue $job): void { $this->next = $job; }
    }
    $provider = (new ReflectionClass(CostProvider::class))->newInstanceWithoutConstructor();
    $provider->connection = new CostConnection;
    $job = (new ReflectionClass(CostJob::class))->newInstanceWithoutConstructor();
    $job->account = new Account; $job->account->id = 8; $job->account->workspace_id = 12;
    $job->account->provider_id = 'account'; $job->options = ['timeline_start_time' => '2026-10-01T00:00:00Z', 'timeline_end_time' => '2026-10-20T00:00:00Z'];
    $job->fixture = $provider;
    function check(bool $ok, string $message): void { if (! $ok) throw new RuntimeException($message); echo "PASS $message\n"; }
    function item(string $id, ?string $key = null): object {
        return (object) ['id' => $id, 'text' => 'short', 'note_tweet' => (object) ['text' => 'full long-form body'],
            'created_at' => '2026-10-02T12:00:00Z', 'attachments' => (object) ['media_keys' => $key ? [$key] : []],
            'public_metrics' => (object) ['like_count' => 13, 'impression_count' => 42]];
    }
    function mediaResponse(array $types): object {
        return (object) ['data' => [], 'includes' => (object) ['media' => array_map(fn ($key, $type) =>
            (object) ['media_key' => $key, 'type' => $type, 'url' => 'https://pbs.example.test/'.$key.'.jpg'], array_keys($types), array_values($types))]];
    }
    function row(string $id): object { return ImportedPost::$rows['12:8:'.$id]; }
    $params = $provider->timelineParams();
    check(! isset($params['expansions']) && ! isset($params['media.fields']), 'timeline refresh never requests expansions or media fields');
    check(str_contains($params['tweet.fields'], 'note_tweet') && str_contains($params['tweet.fields'], 'article') && ! str_contains($params['tweet.fields'], 'non_public_metrics'), 'timeline retains full text articles and public-only historical metrics');
    check($params['pagination_token'] === 'next' && isset($params['start_time'], $params['end_time']), 'timeline retains pagination and age-window bounds');
    $provider->connection->fixtures = [mediaResponse(['photo-key' => 'photo', 'video-key' => 'video'])];
    $job->page([item('photo', 'photo-key'), item('video', 'video-key'), item('text')]);
    check(count($provider->connection->calls) === 1 && $provider->connection->calls[0][0] === 'tweets' && $provider->connection->calls[0][1]['ids'] === 'photo,video', 'first import batches only media posts into one lookup');
    check($provider->connection->calls[0][1]['expansions'] === 'article.cover_media,attachments.media_keys', 'hydration omits unused article body media and author expansions');
    check(row('photo')->content_type === 'photo' && row('video')->content_type === 'video' && row('text')->content_type === 'text', 'new photo video and text imports keep their correct media types');
    check(row('photo')->thumbnail === 'https://pbs.example.test/photo-key.jpg' && row('photo')->text === 'full long-form body', 'first import stores thumbnail and full long-form text');
    $provider->connection->calls = [];
    row('video')->thumbnail = 'imported/cached-video.jpg';
    $job->page([item('photo', 'photo-key'), item('video', 'video-key'), item('text')]);
    check($provider->connection->calls === [] && row('photo')->thumbnail === 'https://pbs.example.test/photo-key.jpg' && row('video')->thumbnail === 'imported/cached-video.jpg', 'repeat refresh makes zero media lookups and preserves both remote and downloaded thumbnails');
    check(count(TwitterPostInsight::$rows) === 18 && TwitterPostInsight::$rows[1]['value'] === 13, 'metrics still update during expansion-free repeat refresh');
    $article = item('article'); $article->article = (object) ['title' => 'Title', 'plain_text' => 'Article body', 'cover_media' => 'cover'];
    $provider->connection->fixtures = [mediaResponse(['cover' => 'photo'])]; $job->page([$article]);
    check(row('article')->content_type === 'article' && row('article')->text === "Title\n\nArticle body" && row('article')->thumbnail === 'https://pbs.example.test/cover.jpg', 'new article keeps its body and fetches its cover once');
    $provider->connection->calls = []; $job->page([$article]);
    check($provider->connection->calls === [], 'repeat article refresh does not expand its cover again');
    $provider->connection->fixtures = [(object) ['error' => true]]; $job->page([item('repair', 'repair-key')]);
    check(row('repair')->content_type === 'unknown' && count(TwitterPostInsight::$rows) === 6, 'metadata failure still stores content and metrics for later recovery');
    $provider->connection->fixtures = [mediaResponse(['repair-key' => 'photo'])]; $job->page([item('repair', 'repair-key')]);
    check(row('repair')->content_type === 'photo' && row('repair')->thumbnail !== '', 'a later refresh repairs missing media metadata');
    $provider->connection->fixtures = [new Abraham\TwitterOAuth\TwitterOAuthException('transport')]; $job->page([item('transport', 'transport-key')]);
    check(row('transport')->text === 'full long-form body' && count(TwitterPostInsight::$rows) === 6, 'transport failure does not discard metrics or content');
    ImportedPost::$rows['other'] = (object) ['workspace_id' => 99, 'account_id' => 88, 'provider_post_id' => 'scoped', 'thumbnail' => 'private.jpg', 'content_type' => 'photo'];
    $provider->connection->fixtures = [mediaResponse(['scoped-key' => 'photo'])]; $job->page([item('scoped', 'scoped-key')]);
    check(row('scoped')->thumbnail !== 'private.jpg', 'media cache is scoped to both workspace and account');
    $provider->connection->calls = [];
    $items = []; for ($i = 0; $i < 101; $i++) $items[] = item('bulk-'.$i, 'bulk-key-'.$i);
    $job->page($items, (object) ['next_token' => 'next-page']);
    check(count($provider->connection->calls) === 2 && count(explode(',', $provider->connection->calls[0][1]['ids'])) === 100 && $provider->connection->calls[1][1]['ids'] === 'bulk-100', 'missing media lookups respect the 100-ID batch limit');
    check($job->next && $job->next->options['pagination_next_token'] === 'next-page' && $job->next->options['timeline_start_time'] === $job->options['timeline_start_time'] && $job->next->options['timeline_end_time'] === $job->options['timeline_end_time'], 'follow-up page retains custom age-window bounds');
}
