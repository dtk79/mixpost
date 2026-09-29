<?php

namespace Inovector\Mixpost\SocialProviders\Threads\Jobs;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inovector\Mixpost\Concerns\Job\OnAnalyticsQueue;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Jobs\DownloadImportedPostThumbnailJob;
use Inovector\Mixpost\Jobs\SocialProviderJob;
use Inovector\Mixpost\Models\ImportedPost;
use Inovector\Mixpost\SocialProviders\Threads\ThreadsProvider;
use Inovector\Mixpost\Support\SocialProviderResponse;

class ImportThreadsPostsJob extends SocialProviderJob
{
    use OnAnalyticsQueue;

    protected function execute(): SocialProviderResponse
    {
        /** @see ThreadsProvider */
        return $this->connectProvider($this->account)->getPosts($this->options['pagination_after'] ?? '');
    }

    protected function processResponse(SocialProviderResponse $response): void
    {
        $items = Arr::get($response->context(), 'data', []);

        $downloadedThumbnails = ImportedPost::downloadedThumbnails($this->account->id, Arr::pluck($items, 'id'));

        $this->importPosts($items, $downloadedThumbnails);
        $this->dispatchThumbnailDownload($items, $downloadedThumbnails);
        $this->dispatchPostInsights($items);

        if ($after = Arr::get($response->context(), 'paging.cursors.after')) {
            $this->dispatchOrAddToBatch((new self($this->account, ['pagination_after' => $after]))->delay(5 * 60));
        }
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
                'text' => $item['text'] ?? '',
                'url' => $item['permalink'] ?? '',
                'thumbnail' => $downloadedThumbnails[$item['id']] ?? $item['thumbnail_url'] ?? $item['media_url'] ?? '',
                'content_type' => $item['media_type'] ?? '',
                'data' => json_encode([
                    'username' => $item['username'] ?? '',
                    'is_quote_post' => $item['is_quote_post'] ?? false,
                ]),
                'created_at' => Carbon::parse($item['timestamp'], 'UTC')->toDateTimeString(),
            ];
        });

        ImportedPost::upsert($data, ['workspace_id', 'account_id', 'provider_post_id'], [
            'text', 'url',
            // Keep the cached path atomically, even if a download completes during this import.
            'thumbnail' => DB::raw("IF(LEFT(thumbnail, 9) = 'imported/', thumbnail, VALUES(thumbnail))"),
            'content_type', 'data', 'created_at',
        ]);
    }

    private function dispatchThumbnailDownload(array $items, array $downloadedThumbnails): void
    {
        $thumbnails = [];

        foreach ($items as $item) {
            if (isset($downloadedThumbnails[$item['id']])) {
                continue;
            }

            $url = $item['thumbnail_url'] ?? $item['media_url'] ?? '';

            if ($url) {
                $thumbnails[$item['id']] = $url;
            }
        }

        foreach (array_chunk($thumbnails, 20, true) as $chunk) {
            DownloadImportedPostThumbnailJob::dispatch($this->account->uuid, $chunk);
        }
    }

    private function dispatchPostInsights(array $items): void
    {
        $workspaceId = WorkspaceManager::current()->id;
        $delaySeconds = 0;

        foreach ($items as $item) {
            $mediaType = $item['media_type'] ?? '';

            // REPOST_FACADE posts return empty insights
            if ($mediaType === 'REPOST_FACADE') {
                continue;
            }

            $this->dispatchOrAddToBatch(
                (new ImportThreadsSinglePostInsightsJob(
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
