<?php

namespace Inovector\Mixpost\SocialProviders\Meta\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\Concerns\AwaitsPlatformProcessing;
use Illuminate\Support\Facades\Log;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\SocialProviders\Meta\InstagramInsights\InstagramFetchDemographics;
use Inovector\Mixpost\SocialProviders\Meta\InstagramInsights\InstagramFetchInsightsTimeSeries;
use Inovector\Mixpost\SocialProviders\Meta\InstagramInsights\InstagramFetchInsightsTotalValue;
use Inovector\Mixpost\SocialProviders\Meta\InstagramInsights\InstagramFetchMediaInsights;
use Inovector\Mixpost\SocialProviders\Meta\Support\InstagramPostOptions;
use Inovector\Mixpost\Support\PostVersionHelpers;
use Inovector\Mixpost\Support\SocialProviderResponse;

trait ManagesInstagramResources
{
    use AwaitsPlatformProcessing;
    use InstagramComments;

    public function getAccount(): SocialProviderResponse
    {
        return $this->getInstagramAccount($this->values['provider_id']);
    }

    public function getEntities(): SocialProviderResponse
    {
        $response = Http::withToken($this->getAccessToken()['access_token'])
            ->get("{$this->resolveApiDomain()}/me/accounts", [
                'fields' => 'id,name,username,picture{url},instagram_business_account',
                'limit' => 200,
            ]);

        return $this->buildResponse($response, function () use ($response) {
            return $response->collect('data')->filter(function ($item) {
                return isset($item['instagram_business_account']);
            })->map(function ($item) {
                return $this->getInstagramAccount($item['instagram_business_account']['id'])->context();
            })->toArray();
        });
    }

    public function getInstagramAccount($id): SocialProviderResponse
    {
        $response = Http::get("{$this->resolveApiDomain()}/$id", [
            'fields' => 'id,name,username,profile_picture_url',
            'access_token' => $this->getAccessToken()['access_token'],
        ]);

        return $this->buildResponse($response, function () use ($response) {
            $data = $response->json();

            return [
                'id' => $data['id'],
                'name' => $data['name'] ?? $data['username'] ?? '',
                'username' => $data['username'] ?? '',
                'image' => $data['profile_picture_url'] ?? '',
            ];
        });
    }

    public function publishPost(string $text, Collection $media, array $params = []): SocialProviderResponse
    {
        if (! $media->count()) {
            return $this->response(SocialProviderResponseStatus::ERROR, ['no_media_selected']);
        }

        $response = null;
        $isReel = Arr::get($params, 'type') === 'reel';
        $isStory = Arr::get($params, 'type') === 'story';
        $collaborators = InstagramPostOptions::collaborators(Arr::get($params, 'collaborators'));

        if ($isReel && $media->count() === 1) {
            if (isset($params['video_thumbs']) && is_array($params['video_thumbs'])) {
                $thumb = PostVersionHelpers::getThumbForMediaId($media->first()->id, $params['video_thumbs']);
            }
            $response = $this->publishInstagramReel(
                $text,
                $media->first(),
                $thumb ?? null,
                $this->audioConfiguration(Arr::get($params, 'audio')),
                $collaborators
            );
        }

        if ($isReel && $media->count() > 1) {
            $response = $this->response(SocialProviderResponseStatus::ERROR, ['reel_supports_one_video']);
        }

        if ($isStory && $media->count() === 1) {
            $response = $this->publishStory($media->first());

            if ($response->hasError()) {
                return $response;
            }

            return $response->useContext([
                'id' => $response->id,
                'data' => [
                    'story' => true,
                ],
            ]);
        }

        if ($isStory && $media->count() > 1) {
            $response = $this->response(SocialProviderResponseStatus::ERROR, ['story_single_media_limit']);
        }

        if (! $isReel && ! $isStory && $media->count() === 1) {
            $response = $this->publishSingleMediaPost($text, $media->first(), $collaborators);
        }

        if (! $isReel && ! $isStory && $media->count() > 1) {
            $response = $this->publishCarouselPost($text, $media, $this->resumeState(Arr::get($params, 'resume')), $this->resumeAttempt(Arr::get($params, 'resume')), $collaborators);
        }

        if ($response && $response->isPending()) {
            return $response;
        }

        if ($response && $response->hasError()) {
            return $response;
        }

        // If we have a response and if it is successful,
        // we need to extract the shortcode of the post and
        // attach it to the response.
        $postResponse = $this->getPost($response->id, 'shortcode');

        $data = [];

        if ($postResponse->shortcode) {
            $data['shortcode'] = $postResponse->shortcode;
        }

        return $response->useContext([
            'id' => $response->id,
            'data' => $data,
        ]);
    }

    public function publishSingleMediaPost(string $text, Media $mediaItem, array $collaborators = []): SocialProviderResponse
    {
        $data = [
            'access_token' => $this->getAccessToken()['access_token'],
            'caption' => $text,
            'alt_text' => $mediaItem->alt_text,
            ...$this->collaboratorsParameter($collaborators),
        ];

        if ($mediaItem->isVideo()) {
            $data['media_type'] = 'VIDEO';
            $data['video_url'] = $mediaItem->getUrl();
        }

        if ($mediaItem->isImage()) {
            $data['image_url'] = $mediaItem->getUrl();
        }

        $response = $this->buildResponse(
            $this->http()::timeout($mediaItem->isImage() ? 30 : 100)
                ->post("{$this->resolveApiDomain()}/{$this->values['provider_id']}/media", $data)
        );

        if ($response->hasError()) {
            return $response;
        }

        return $this->publishContainer($response->id);
    }

    public function publishInstagramReel(string $text, Media $mediaItem, ?Media $thumb = null, array $audioConfiguration = [], array $collaborators = []): SocialProviderResponse
    {
        if (! $mediaItem->isVideo()) {
            return $this->response(SocialProviderResponseStatus::ERROR, ['reel_only_video_allowed']);
        }

        $videoUrl = $this->instagramReelVideoUrl($mediaItem);

        if (! $videoUrl) {
            return $this->response(SocialProviderResponseStatus::ERROR, ['social_video_optimization_pending']);
        }

        $data = [
            'access_token' => $this->getAccessToken()['access_token'],
            'caption' => $text,
            'media_type' => 'REELS',
            'video_url' => $videoUrl,
            'alt_text' => $mediaItem->alt_text,
            ...$this->collaboratorsParameter($collaborators),
        ];

        if ($thumb) {
            $data['cover_url'] = $thumb->getUrl();
        }

        if ($audioConfiguration) {
            $data['audio_configuration'] = json_encode($audioConfiguration);
        }

        $response = $this->buildResponse(
            $this->http()::timeout(60)->post("{$this->resolveApiDomain()}/{$this->values['provider_id']}/media", $data)
        );

        if ($response->hasError()) {
            return $response;
        }

        return $this->publishContainer($response->id);
    }

    private function instagramReelVideoUrl(Media $mediaItem): ?string
    {
        if ($url = $mediaItem->getConversionUrl('social_video')) {
            return $url;
        }

        if ($url = $mediaItem->getConversionUrl('instagram_reel')) {
            return $url;
        }

        if ($mediaItem->size > 300 * 1024 * 1024) {
            return null;
        }

        return $mediaItem->getUrl();
    }

    public function publishCarouselPost(string $text, Collection $media, array $state = [], int $attempt = 0, array $collaborators = []): array|SocialProviderResponse
    {
        $mediaContainerIds = Arr::get($state, 'container_ids', []);

        if ($state) {
            $settled = $this->settlePendingCarouselItem($state, $attempt);

            if (! $settled->isOk()) {
                return $settled;
            }

            $mediaContainerIds[] = $settled->id();
        }

        foreach ($media->slice(count($mediaContainerIds)) as $mediaItem) {
            $data = [
                'access_token' => $this->getAccessToken()['access_token'],
                'is_carousel_item' => true,
            ];

            if ($mediaItem->isImage()) {
                $data['alt_text'] = $mediaItem->alt_text;
                $data['image_url'] = $mediaItem->getUrl();
            }

            if ($mediaItem->isVideo()) {
                $data['media_type'] = 'VIDEO';
                $data['video_url'] = $mediaItem->getUrl();
            }

            $mediaContainerResponse = $this->buildResponse(Http::post("{$this->resolveApiDomain()}/{$this->values['provider_id']}/media", $data));

            if ($mediaContainerResponse->hasError()) {
                return $mediaContainerResponse;
            }

            // Instagram encodes video children asynchronously. Hand the wait back to the queue and
            // resume from the containers already built, so nothing is uploaded twice.
            if ($mediaItem->isVideo()) {
                return $this->awaitingProcessing([
                    'container_ids' => $mediaContainerIds,
                    'pending_container_id' => $mediaContainerResponse->id,
                ]);
            }

            $mediaContainerIds[] = $mediaContainerResponse->id;
        }

        // Collaborators belong to the carousel itself: Instagram refuses them on its items.
        $carouselContainer = $this->buildResponse(Http::post("{$this->resolveApiDomain()}/{$this->values['provider_id']}/media", [
            'access_token' => $this->getAccessToken()['access_token'],
            'media_type' => 'CAROUSEL',
            'children' => implode(',', $mediaContainerIds),
            'caption' => $text,
            ...$this->collaboratorsParameter($collaborators),
        ]));

        if ($carouselContainer->hasError()) {
            return $carouselContainer;
        }

        return $this->publishContainer($carouselContainer->id);
    }

    public function publishStory(Media $mediaItem): SocialProviderResponse
    {
        $data = [
            'access_token' => $this->getAccessToken()['access_token'],
            'media_type' => 'STORIES',
        ];

        if ($mediaItem->isVideo()) {
            $data['video_url'] = $mediaItem->getUrl();
        }

        if ($mediaItem->isImage()) {
            $data['image_url'] = $mediaItem->getUrl();
        }

        $response = $this->buildResponse(
            $this->http()::timeout($mediaItem->isImage() ? 30 : 100)
                ->post("{$this->resolveApiDomain()}/{$this->values['provider_id']}/media", $data)
        );

        if ($response->hasError()) {
            return $response;
        }

        return $this->publishContainer($response->id);
    }

    public function publishContainer(string|int $itemContainerId): array|SocialProviderResponse
    {
        // Stay within the publishing worker's timeout. Never publish an unknown
        // or still-processing container, and never recreate it on uncertainty.
        $deadline = microtime(true) + 360;
        for ($check = 0; $check < 12; $check++) {
            $responseContainer = $this->getContainer($itemContainerId);
            if ($responseContainer->status_code !== 'IN_PROGRESS') {
                break;
            }

            if ($check < 11 && microtime(true) + 30 < $deadline) {
                $this->waitForInstagramContainerPoll();
            } else {
                break;
            }
        }

        Log::info('mixpost.instagram_container_result', [
            'account_id' => $this->values['provider_id'],
            'container_id' => (string) $itemContainerId,
            'status_code' => $responseContainer->status_code,
            'status' => $responseContainer->status,
        ]);

        if (! $responseContainer->status_code) {
            return $this->response(SocialProviderResponseStatus::ERROR, $responseContainer->context());
        }

        // Check specific endpoint status
        if ($responseContainer->status_code === 'ERROR') {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                'errors' => [$responseContainer->status],
                'phase' => 'instagram_container_processing',
                'container_id' => (string) $itemContainerId,
                'container_status' => 'ERROR',
            ]);
        }

        if ($responseContainer->status_code === 'EXPIRED') {
            return $this->response(SocialProviderResponseStatus::ERROR, ['session_expired']);
        }

        if ($responseContainer->status_code === 'PUBLISHED') {
            return $this->response(SocialProviderResponseStatus::ERROR, ['media_already_published']);
        }

        if ($responseContainer->status_code !== 'FINISHED') {
            return $this->response(SocialProviderResponseStatus::ERROR, [
                'errors' => ['Instagram has not confirmed that the upload is ready. Review the container before retrying.'],
                'container_id' => (string) $itemContainerId,
                'container_status' => $responseContainer->status_code,
            ]);
        }

        $response = $this->http()::timeout(60)->withToken($this->getAccessToken()['access_token'])
            ->post("{$this->resolveApiDomain()}/{$this->values['provider_id']}/media_publish", [
                'creation_id' => $itemContainerId,
            ]);

        return $this->buildResponse($response);
    }

    protected function waitForInstagramContainerPoll(): void
    {
        sleep(30);
    }

    public function getContainer($containerId): SocialProviderResponse
    {
        $response = Http::timeout(30)->get("{$this->resolveApiDomain()}/$containerId", [
            'access_token' => $this->getAccessToken()['access_token'],
            'fields' => 'status,status_code',
        ]);

        return $this->buildResponse($response);
    }

    public function getContentPublishLimit(): SocialProviderResponse
    {
        $response = Http::get("{$this->resolveApiDomain()}/{$this->values['provider_id']}/content_publishing_limit", [
            'access_token' => $this->getAccessToken()['access_token'],
        ]);

        return $this->buildResponse($response);
    }

    public function getAccountMetrics(): SocialProviderResponse
    {
        $response = Http::get("{$this->resolveApiDomain()}/{$this->values['provider_id']}", [
            'fields' => 'followers_count',
            'access_token' => $this->getAccessToken()['access_token'],
        ]);

        return $this->buildResponse($response);
    }

    public function getInsightsTimeSeries(Carbon $since, Carbon $until): SocialProviderResponse
    {
        $insights = new InstagramFetchInsightsTimeSeries(
            accessToken: $this->getAccessToken()['access_token'],
            apiDomain: $this->resolveApiDomain(),
            values: $this->values,
        );

        return $insights->handle($since, $until);
    }

    public function getInsightsTotalValue(Carbon $since, Carbon $until, ?string $breakdown = null): SocialProviderResponse
    {
        $insights = new InstagramFetchInsightsTotalValue(
            accessToken: $this->getAccessToken()['access_token'],
            apiDomain: $this->resolveApiDomain(),
            values: $this->values,
        );

        return $insights->handle($since, $until, $breakdown);
    }

    public function getDemographics(string $metric, string $timeframe, string $breakdown): SocialProviderResponse
    {
        $demographics = new InstagramFetchDemographics(
            accessToken: $this->getAccessToken()['access_token'],
            apiDomain: $this->resolveApiDomain(),
            values: $this->values,
        );

        return $demographics->handle($metric, $timeframe, $breakdown);
    }

    public function getMediaInsights(string $mediaId, array $metrics): SocialProviderResponse
    {
        $fetcher = new InstagramFetchMediaInsights(
            accessToken: $this->getAccessToken()['access_token'],
            apiDomain: $this->resolveApiDomain(),
        );

        return $fetcher->handle($mediaId, $metrics);
    }

    public function getStoryNavigationBreakdown(string $storyId): SocialProviderResponse
    {
        $response = Http::get("{$this->resolveApiDomain()}/{$storyId}/insights", [
            'access_token' => $this->getAccessToken()['access_token'],
            'metric' => 'navigation',
            'breakdown' => 'story_navigation_action_type',
        ]);

        return $this->buildResponse($response);
    }

    public function getPost(string $mediaId, string $fields = 'id,ig_id'): SocialProviderResponse
    {
        $response = $this->http()::withToken($this->getAccessToken()['access_token'])
            ->get("{$this->resolveApiDomain()}/$mediaId", [
                'fields' => $fields,
            ]);

        return $this->buildResponse($response);
    }

    public function getBusinessDiscovery(string $username): SocialProviderResponse
    {
        $response = Http::withToken($this->getAccessToken()['access_token'])
            ->get("{$this->resolveApiDomain()}/{$this->values['provider_id']}", [
                'fields' => "business_discovery.username({$username}){name,username,profile_picture_url,followers_count,media_count,media.limit(25){id,caption,like_count,comments_count,media_type,permalink,timestamp}}",
            ]);

        return $this->buildResponse($response);
    }

    public function getMedia(string $paginationAfter = '', bool $includeInsights = true): SocialProviderResponse
    {
        $fields = 'id,caption,comments_count,is_comment_enabled,is_shared_to_feed,like_count,media_product_type,media_type,media_url,permalink,shortcode,thumbnail_url,timestamp,username';

        if ($includeInsights) {
            $fields .= ',insights.metric(ig_reels_avg_watch_time,ig_reels_video_view_total_time,shares,comments,likes,saved,total_interactions,navigation,follows,profile_visits,profile_activity,reach,views,replies)';
        }

        $data = [
            'access_token' => $this->getAccessToken()['access_token'],
            'since' => Carbon::now('UTC')->subYear()->toDateString(),
            'until' => Carbon::tomorrow('UTC')->toDateString(),
            'limit' => 100,
            'fields' => $fields,
        ];

        if ($paginationAfter) {
            $data['after'] = $paginationAfter;
        }

        $response = Http::get("{$this->resolveApiDomain()}/{$this->values['provider_id']}/media", $data);

        return $this->buildResponse($response);
    }

    public function getStories(): SocialProviderResponse
    {
        $response = Http::get("{$this->resolveApiDomain()}/{$this->values['provider_id']}/stories", [
            'access_token' => $this->getAccessToken()['access_token'],
            'fields' => 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp,username',
        ]);

        return $this->buildResponse($response);
    }

    /**
     * Each username is invited to the post, which shows on their profile too once they accept.
     * Instagram takes them on a feed post, a carousel and a Reel, never on a Story.
     */
    protected function collaboratorsParameter(array $collaborators): array
    {
        if (! $collaborators) {
            return [];
        }

        return ['collaborators' => json_encode($collaborators)];
    }

    /**
     * Resolve the carousel child left encoding by the previous attempt. Returns it as an OK response
     * carrying the finished container id, so the caller can add it to the ones already built.
     */
    protected function settlePendingCarouselItem(array $state, int $attempt): SocialProviderResponse
    {
        if (! $containerId = Arr::get($state, 'pending_container_id')) {
            return $this->response(SocialProviderResponseStatus::ERROR, ['carousel_item_processing_timeout']);
        }

        $container = $this->getContainer($containerId);

        if ($container->status_code === 'IN_PROGRESS') {
            return $this->awaitingProcessing($state, $attempt);
        }

        if ($container->status_code !== 'FINISHED') {
            return $this->response(SocialProviderResponseStatus::ERROR, $container->context());
        }

        return $this->response(SocialProviderResponseStatus::OK, ['id' => $containerId]);
    }
}
