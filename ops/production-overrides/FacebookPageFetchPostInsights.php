<?php

namespace Inovector\Mixpost\SocialProviders\Meta\FacebookPageInsights;

use Inovector\Mixpost\SocialProviders\Meta\Abstracts\FacebookPageInsightsAPI;
use Inovector\Mixpost\Support\SocialProviderResponse;

class FacebookPageFetchPostInsights extends FacebookPageInsightsAPI
{
    public function handle(string $postId): SocialProviderResponse
    {
        $response = $this->http()::withToken($this->pageToken)
            ->timeout(self::REQUEST_TIMEOUT)
            ->get("{$this->apiUrl}/{$this->apiVersion}/{$postId}/insights", [
                'period' => 'day',
                'metric' => implode(',', $this->metrics()),
            ]);

        return $this->buildResponse($response);
    }

    protected function metrics(): array
    {
        return [
            'post_video_views_organic',
            'post_video_views_paid',
            'post_video_views',
            // Keep historical unique-view rows, but never request this rejected metric.
            'post_video_views_60s_excludes_shorter',
            'post_video_social_actions_count_unique',
            'post_video_view_time',
            'post_video_view_time_organic',
        ];
    }
}
