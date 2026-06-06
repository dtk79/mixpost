<?php

namespace Inovector\Mixpost\SocialProviders\Meta\Jobs;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Inovector\Mixpost\Concerns\Job\OnAnalyticsQueue;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Jobs\DownloadImportedPostThumbnailJob;
use Inovector\Mixpost\Jobs\SocialProviderJob;
use Inovector\Mixpost\Models\ImportedPost;
use Inovector\Mixpost\SocialProviders\Meta\Enums\InstagramPostInsightType;
use Inovector\Mixpost\SocialProviders\Meta\InstagramProvider;
use Inovector\Mixpost\SocialProviders\Meta\Models\InstagramPostInsight;
use Inovector\Mixpost\SocialProviders\Meta\Models\InstagramPostInsightHistory;
use Inovector\Mixpost\Support\SocialProviderResponse;

class ImportInstagramMediaJob extends SocialProviderJob
{
    use OnAnalyticsQueue;

    protected function execute(): SocialProviderResponse
    {
        /** @see InstagramProvider */
        $provider = $this->connectProvider($this->account);
        $includeInsights = $this->options['include_insights'] ?? $this->account->provider !== 'instagram_standalone';

        $response = $provider->getMedia($this->options['pagination_after'] ?? '', $includeInsights);

        if ($includeInsights && $this->isMediaInsightAvailabilityError($response)) {
            return $provider->getMedia($this->options['pagination_after'] ?? '', false);
        }

        return $response;
    }

    protected function processResponse(SocialProviderResponse $response): void
    {
        $items = $response->context()['data'];

        $this->importMedia($items);
        $this->importMediaInsights($items);
        $this->dispatchThumbnailDownload($items);

        if ($after = Arr::get($response->context(), 'paging.cursors.after')) {
            $this->dispatchOrAddToBatch((new self($this->account, ['pagination_after' => $after]))->delay(5 * 60));
        }
    }

    protected function importMedia(array $items): void
    {
        $workspaceId = WorkspaceManager::current()->id;
        $accountId = $this->account->id;

        $data = Arr::map($items, function ($item) use ($workspaceId, $accountId) {
            return [
                'workspace_id' => $workspaceId,
                'account_id' => $accountId,
                'provider_post_id' => $item['id'],
                'text' => $item['caption'] ?? '',
                'url' => $item['permalink'] ?? '',
                'thumbnail' => $item['thumbnail_url'] ?? $item['media_url'] ?? '',
                'content_type' => $item['media_product_type'] ?? '',
                'data' => json_encode([
                    'media_type' => $item['media_type'] ?? '',
                    'shortcode' => $item['shortcode'] ?? '',
                    'username' => $item['username'] ?? '',
                    'is_shared_to_feed' => $item['is_shared_to_feed'] ?? false,
                    'is_comment_enabled' => $item['is_comment_enabled'] ?? null,
                ]),
                'created_at' => Carbon::parse($item['timestamp'], 'UTC')->toDateString(),
            ];
        });

        ImportedPost::upsert($data, ['workspace_id', 'account_id', 'provider_post_id'], ['text', 'url', 'thumbnail', 'content_type', 'data']);
    }

    protected function dispatchThumbnailDownload(array $items): void
    {
        $thumbnails = [];

        foreach ($items as $item) {
            $url = $item['thumbnail_url'] ?? $item['media_url'] ?? '';

            if ($url) {
                $thumbnails[$item['id']] = $url;
            }
        }

        foreach (array_chunk($thumbnails, 20, true) as $chunk) {
            DownloadImportedPostThumbnailJob::dispatch($this->account->uuid, $chunk);
        }
    }

    protected function importMediaInsights(array $items): void
    {
        $workspaceId = WorkspaceManager::current()->id;
        $accountId = $this->account->id;
        $today = Carbon::today('UTC')->toDateString();

        $insightData = [];
        $historyData = [];

        /** @var InstagramProvider $provider */
        $provider = $this->connectProvider($this->account);

        foreach ($items as $item) {
            $insights = $this->resolveMediaInsights($provider, $item);

            foreach ($insights as $insight) {
                $type = InstagramPostInsightType::fromApiName($insight['name'] ?? '');

                if (! $type) {
                    continue;
                }

                $value = $insight['values'][0]['value'] ?? ($insight['total_value']['value'] ?? 0);
                $value = is_int($value) ? $value : 0;

                $insightData[] = [
                    'workspace_id' => $workspaceId,
                    'account_id' => $accountId,
                    'provider_post_id' => $item['id'],
                    'type' => $type,
                    'value' => $value,
                    'updated_at' => Carbon::now(),
                ];

                $historyData[] = [
                    'workspace_id' => $workspaceId,
                    'account_id' => $accountId,
                    'provider_post_id' => $item['id'],
                    'type' => $type,
                    'value' => $value,
                    'date' => $today,
                ];
            }
        }

        if ($insightData) {
            InstagramPostInsight::upsert(
                $insightData,
                ['workspace_id', 'account_id', 'provider_post_id', 'type'],
                ['value', 'updated_at']
            );

            InstagramPostInsightHistory::upsert(
                $historyData,
                ['workspace_id', 'account_id', 'provider_post_id', 'type', 'date'],
                ['value']
            );
        }
    }

    protected function resolveMediaInsights(InstagramProvider $provider, array $item): array
    {
        $embedded = Arr::get($item, 'insights.data');

        if (is_array($embedded)) {
            return $embedded;
        }

        $metrics = $this->metricNamesForMedia($item);

        if (! $metrics) {
            return [];
        }

        $response = $provider->getMediaInsights($item['id'], $metrics);

        if (! $response->hasError()) {
            return Arr::get($response->context(), 'data', []);
        }

        if ($this->isMediaInsightAvailabilityError($response)) {
            return [];
        }

        $insights = [];

        foreach ($metrics as $metric) {
            $metricResponse = $provider->getMediaInsights($item['id'], [$metric]);

            if ($metricResponse->hasError()) {
                continue;
            }

            array_push($insights, ...Arr::get($metricResponse->context(), 'data', []));
        }

        return $insights;
    }

    protected function metricNamesForMedia(array $item): array
    {
        return array_values(array_unique(array_map(
            fn (InstagramPostInsightType $type) => $type->apiName(),
            InstagramPostInsightType::forMediaType($item['media_product_type'] ?? '')
        )));
    }

    protected function isMediaInsightAvailabilityError(SocialProviderResponse $response): bool
    {
        $errorCode = Arr::get($response->context(), 'error.code');
        $errorSubcode = Arr::get($response->context(), 'error.error_subcode');

        return $errorCode === 10 || ($errorCode === 100 && $errorSubcode === 2108006);
    }
}
