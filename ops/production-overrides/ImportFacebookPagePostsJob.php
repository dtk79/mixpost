<?php

namespace Inovector\Mixpost\SocialProviders\Meta\Jobs;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inovector\Mixpost\Concerns\Job\OnAnalyticsQueue;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Jobs\DownloadImportedPostThumbnailJob;
use Inovector\Mixpost\Jobs\SocialProviderJob;
use Inovector\Mixpost\Models\ImportedPost;
use Inovector\Mixpost\SocialProviders\Meta\Enums\FacebookPostInsightType;
use Inovector\Mixpost\SocialProviders\Meta\FacebookPageInsights\FacebookPageFetchPosts;
use Inovector\Mixpost\SocialProviders\Meta\FacebookPageProvider;
use Inovector\Mixpost\SocialProviders\Meta\Models\FacebookPostInsight;
use Inovector\Mixpost\SocialProviders\Meta\Models\FacebookPostInsightHistory;
use Inovector\Mixpost\Support\SocialProviderResponse;

class ImportFacebookPagePostsJob extends SocialProviderJob
{
    use OnAnalyticsQueue;

    protected function execute(): SocialProviderResponse
    {
        $params = ['limit' => $this->limit()];

        if ($after = Arr::get($this->options, 'pagination_after')) {
            $params['after'] = $after;
        }

        /** @var FacebookPageProvider $response */
        return $this->connectProvider($this->account)->getPosts($params);
    }

    protected function handleResponse(SocialProviderResponse $response): bool
    {
        if ($this->isDataTooLargeError($response) && $this->limit() > FacebookPageFetchPosts::MIN_LIMIT) {
            $this->retryWithReducedLimit();

            return false;
        }

        return parent::handleResponse($response);
    }

    protected function processResponse(SocialProviderResponse $response): void
    {
        $items = $response->data;

        $downloadedThumbnails = ImportedPost::downloadedThumbnails($this->account->id, Arr::pluck($items, 'id'));

        $this->importPosts($items, $downloadedThumbnails);
        $this->importPostInsights($items);
        $this->dispatchThumbnailDownload($items, $downloadedThumbnails);
        $this->dispatchPerPostInsights($items);

        if (! Arr::get($response->context(), 'paging.next')) {
            return;
        }

        if ($after = Arr::get($response->context(), 'paging.cursors.after')) {
            $this->dispatchOrAddToBatch((new self($this->account, [
                'pagination_after' => $after,
                'limit' => $this->limit(),
            ]))->delay(60 * 5));
        }
    }

    private function limit(): int
    {
        return Arr::get($this->options, 'limit', FacebookPageFetchPosts::DEFAULT_LIMIT);
    }

    private function isDataTooLargeError(SocialProviderResponse $response): bool
    {
        if (! $response->hasError()) {
            return false;
        }

        return Arr::get($response->context(), 'error.code') === 1;
    }

    private function retryWithReducedLimit(): void
    {
        $options = array_merge($this->options, [
            'limit' => max(FacebookPageFetchPosts::MIN_LIMIT, intdiv($this->limit(), 2)),
        ]);

        $this->dispatchOrAddToBatch((new self($this->account, $options))->delay(30));
    }

    private function importPosts(array $items, array $downloadedThumbnails): void
    {
        $workspaceId = WorkspaceManager::current()->id;
        $accountId = $this->account->id;

        $data = Arr::map($items, function ($item) use ($workspaceId, $accountId, $downloadedThumbnails) {
            return [
                'workspace_id' => $workspaceId,
                'account_id' => $accountId,
                'provider_post_id' => $item['id'],
                'text' => $item['message'] ?? $item['story'] ?? '',
                'url' => $item['permalink_url'] ?? '',
                'thumbnail' => $downloadedThumbnails[$item['id']] ?? $item['full_picture'] ?? null,
                'content_type' => $item['status_type'] ?? '',
                'data' => json_encode([
                    'is_popular' => $item['is_popular'] ?? false,
                ]),
                'created_at' => Carbon::parse($item['created_time'], 'UTC')->toDateTimeString(),
            ];
        });

        ImportedPost::upsert($data, ['workspace_id', 'account_id', 'provider_post_id'], [
            'text', 'url',
            // Keep the cached path atomically, even if a download completes during this import.
            'thumbnail' => DB::raw("IF(LEFT(thumbnail, 9) = 'imported/', thumbnail, VALUES(thumbnail))"),
            'content_type', 'data', 'created_at',
        ]);
    }

    private function importPostInsights(array $items): void
    {
        $workspaceId = WorkspaceManager::current()->id;
        $accountId = $this->account->id;
        $today = Carbon::today('UTC')->toDateString();

        $insightData = [];
        $historyData = [];

        foreach ($items as $item) {
            $postId = $item['id'];

            $engagementMetrics = [
                [FacebookPostInsightType::REACTIONS, Arr::get($item, 'reactions.summary.total_count', 0)],
                [FacebookPostInsightType::COMMENTS, Arr::get($item, 'comments.summary.total_count', 0)],
                [FacebookPostInsightType::SHARES, Arr::get($item, 'shares.count', 0)],
            ];

            foreach ($engagementMetrics as [$type, $value]) {
                $value = is_int($value) ? $value : 0;

                $insightData[] = [
                    'workspace_id' => $workspaceId,
                    'account_id' => $accountId,
                    'provider_post_id' => $postId,
                    'type' => $type,
                    'value' => $value,
                    'updated_at' => Carbon::now(),
                ];

                $historyData[] = [
                    'workspace_id' => $workspaceId,
                    'account_id' => $accountId,
                    'provider_post_id' => $postId,
                    'type' => $type,
                    'value' => $value,
                    'date' => $today,
                ];
            }

            $insights = Arr::get($item, 'insights.data', []);

            foreach ($insights as $insight) {
                $type = FacebookPostInsightType::fromApiName($insight['name'] ?? '');

                if (! $type) {
                    continue;
                }

                $value = $insight['values'][0]['value'] ?? 0;
                $value = is_int($value) ? $value : 0;

                $insightData[] = [
                    'workspace_id' => $workspaceId,
                    'account_id' => $accountId,
                    'provider_post_id' => $postId,
                    'type' => $type,
                    'value' => $value,
                    'updated_at' => Carbon::now(),
                ];

                $historyData[] = [
                    'workspace_id' => $workspaceId,
                    'account_id' => $accountId,
                    'provider_post_id' => $postId,
                    'type' => $type,
                    'value' => $value,
                    'date' => $today,
                ];
            }
        }

        if ($insightData) {
            FacebookPostInsight::upsert(
                $insightData,
                ['workspace_id', 'account_id', 'provider_post_id', 'type'],
                ['value', 'updated_at']
            );

            FacebookPostInsightHistory::upsert(
                $historyData,
                ['workspace_id', 'account_id', 'provider_post_id', 'type', 'date'],
                ['value']
            );
        }
    }

    private function dispatchThumbnailDownload(array $items, array $downloadedThumbnails): void
    {
        $thumbnails = [];

        foreach ($items as $item) {
            if (isset($downloadedThumbnails[$item['id']])) {
                continue;
            }

            $url = $item['full_picture'] ?? '';

            if ($url) {
                $thumbnails[$item['id']] = $url;
            }
        }

        foreach (array_chunk($thumbnails, 20, true) as $chunk) {
            DownloadImportedPostThumbnailJob::dispatch($this->account->uuid, $chunk);
        }
    }

    private function dispatchPerPostInsights(array $items): void
    {
        $workspaceId = WorkspaceManager::current()->id;
        $delaySeconds = 0;

        foreach ($items as $item) {
            $statusType = $item['status_type'] ?? '';

            if (! in_array($statusType, ['added_video', 'shared_story'])) {
                continue;
            }

            $this->dispatchOrAddToBatch(
                (new ImportFacebookSinglePostInsightsJob(
                    $this->account,
                    [
                        'workspace_id' => $workspaceId,
                        'provider_post_id' => $item['id'],
                    ]
                ))->delay(Carbon::now('UTC')->addSeconds($delaySeconds))
            );

            $delaySeconds += 5;
        }
    }
}
