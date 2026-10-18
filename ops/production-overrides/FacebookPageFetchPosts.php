<?php

namespace Inovector\Mixpost\SocialProviders\Meta\FacebookPageInsights;

use Inovector\Mixpost\SocialProviders\Meta\Abstracts\FacebookPageInsightsAPI;
use Inovector\Mixpost\Support\SocialProviderResponse;

class FacebookPageFetchPosts extends FacebookPageInsightsAPI
{
    public const DEFAULT_LIMIT = 100;

    public const MIN_LIMIT = 5;

    public function handle(array $params = []): SocialProviderResponse
    {
        $data = array_merge([
            'limit' => self::DEFAULT_LIMIT,
            'fields' => implode(',', $this->fields()),
            'show_description_from_api_doc' => false,
        ], $params);

        $response = $this->http()::withToken($this->pageToken)
            ->timeout(self::REQUEST_TIMEOUT)
            ->get("{$this->apiUrl}/{$this->apiVersion}/{$this->values['provider_id']}/posts", $data);

        return $this->buildResponse($response);
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
            // Meta rejects post_video_views_unique and consequently the entire posts request.
            'insights.metric(post_media_view,post_reactions_like_total,post_reactions_love_total,post_reactions_wow_total,post_reactions_haha_total,post_reactions_sorry_total,post_reactions_anger_total,post_clicks,post_video_views,post_video_avg_time_watched).limit(0)',
        ];
    }
}
