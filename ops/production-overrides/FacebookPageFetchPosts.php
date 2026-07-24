<?php

namespace Inovector\Mixpost\SocialProviders\Meta\FacebookPageInsights;

use Inovector\Mixpost\Concerns\UsesHttp;
use Inovector\Mixpost\SocialProviders\Meta\Concerns\UsesResponseBuilder;
use Inovector\Mixpost\Support\SocialProviderResponse;

class FacebookPageFetchPosts
{
    use UsesHttp;
    use UsesResponseBuilder;

    public function __construct(
        public readonly string $pageToken,
        public readonly string $apiUrl,
        public readonly string $apiVersion,
        public readonly array $values = [],
    ) {}

    public function handle(array $params = []): SocialProviderResponse
    {
        $params = $this->normalizePaginationParams($params);

        $data = array_merge([
            'limit' => 25,
            'fields' => implode(',', $this->fields()),
            'show_description_from_api_doc' => false,
        ], $params);

        $response = $this->http()::withToken($this->pageToken)
            ->get("{$this->apiUrl}/{$this->apiVersion}/{$this->values['provider_id']}/posts", $data);

        $result = $this->buildResponse($response);

        if (! $result->isOk() || isset($params['after'])) {
            return $result;
        }

        $reels = $this->http()::withToken($this->pageToken)
            ->get("{$this->apiUrl}/{$this->apiVersion}/{$this->values['provider_id']}/video_reels", [
                'limit' => 25,
                'fields' => implode(',', $this->reelFields()),
                'show_description_from_api_doc' => false,
            ]);

        $reelsResult = $this->buildResponse($reels);

        if (! $reelsResult->isOk()) {
            return $result;
        }

        $context = $result->context();
        $context['data'] = $this->mergeById($context['data'] ?? [], $this->normalizeReels($reelsResult->data ?? []));

        return $result->useContext($context);
    }

    protected function fields(): array
    {
        return [
            'id',
            'message',
            'story',
            'created_time',
            'full_picture',
            'permalink_url',
            'status_type',
            'is_popular',
            'reactions.summary(total_count).limit(0)',
            'comments.summary(total_count).limit(0).as(comments)',
            'shares',
            'insights.metric(post_media_view,post_reactions_like_total,post_reactions_love_total,post_reactions_wow_total,post_reactions_haha_total,post_reactions_sorry_total,post_reactions_anger_total,post_clicks,post_video_views,post_video_views_unique,post_video_avg_time_watched).limit(0)',
        ];
    }

    protected function reelFields(): array
    {
        return [
            'id',
            'description',
            'created_time',
            'picture',
            'permalink_url',
            'views',
        ];
    }

    protected function normalizePaginationParams(array $params): array
    {
        if (isset($params['pagination_after']) && ! isset($params['after'])) {
            $params['after'] = $params['pagination_after'];
        }

        unset($params['pagination_after']);

        return $params;
    }

    protected function normalizeReels(array $reels): array
    {
        return array_map(function (array $reel) {
            $views = $reel['views'] ?? null;
            $permalink = $reel['permalink_url'] ?? '';

            if (str_starts_with($permalink, '/')) {
                $permalink = "https://www.facebook.com$permalink";
            }

            return [
                'id' => $reel['id'],
                'message' => $reel['description'] ?? '',
                'created_time' => $reel['created_time'],
                'full_picture' => $reel['picture'] ?? null,
                'permalink_url' => $permalink,
                'status_type' => 'reel',
                'is_popular' => false,
                'insights' => is_numeric($views) ? [
                    'data' => [
                        ['name' => 'post_media_view', 'values' => [['value' => (int) $views]]],
                        ['name' => 'post_video_views', 'values' => [['value' => (int) $views]]],
                    ],
                ] : ['data' => []],
            ];
        }, $reels);
    }

    protected function mergeById(array $posts, array $reels): array
    {
        $items = [];

        foreach (array_merge($posts, $reels) as $item) {
            $items[$item['id']] = $item;
        }

        return array_values($items);
    }
}
