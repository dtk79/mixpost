<?php

namespace Inovector\Mixpost\SocialProviders\Twitter\Jobs;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Inovector\Mixpost\Concerns\Job\OnAnalyticsQueue;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Jobs\SocialProviderJob;
use Inovector\Mixpost\Models\ImportedPost;
use Inovector\Mixpost\SocialProviders\Twitter\Enums\TwitterPostInsightType;
use Inovector\Mixpost\SocialProviders\Twitter\Models\TwitterPostInsight;
use Inovector\Mixpost\SocialProviders\Twitter\Models\TwitterPostInsightHistory;
use Inovector\Mixpost\SocialProviders\Twitter\TwitterProvider;
use Inovector\Mixpost\Support\SocialProviderResponse;

class ImportTwitterPostsJob extends SocialProviderJob
{
    use OnAnalyticsQueue;

    protected function execute(): SocialProviderResponse
    {
        /** @var TwitterProvider $provider */
        $provider = $this->connectProvider($this->account);

        if ($provider->getTier() === 'free') {
            return $this->response(SocialProviderResponseStatus::OK, []);
        }

        return $provider->getUserTweetTimeline(
            $this->account->provider_id,
            $this->options['pagination_next_token'] ?? ''
        );
    }

    protected function processResponse(SocialProviderResponse $response): void
    {
        $context = $response->context();
        $items = $context['data'] ?? [];

        if (empty($items)) {
            return;
        }

        $mediaMap = $this->buildMediaMap($context['includes'] ?? null);

        $this->importPosts($items, $mediaMap);
        $this->importPostInsights($items);

        $meta = $context['meta'] ?? null;

        if ($meta && isset($meta->next_token)) {
            $this->dispatchOrAddToBatch((new self($this->account, ['pagination_next_token' => $meta->next_token]))->delay(60));
        }
    }

    private function buildMediaMap($includes): array
    {
        $map = [];

        if (! $includes || ! isset($includes->media)) {
            return $map;
        }

        foreach ($includes->media as $media) {
            $map[$media->media_key] = [
                'type' => $media->type ?? 'unknown',
                'thumbnail' => $media->url ?? $media->preview_image_url ?? '',
            ];
        }

        return $map;
    }

    private function resolveText($item): string
    {
        if (! isset($item->article)) {
            return $item->text ?? '';
        }

        $text = trim(implode("\n\n", array_filter([
            trim((string) ($item->article->title ?? '')),
            trim((string) ($item->article->plain_text ?? $item->article->preview_text ?? '')),
        ])));

        return $text !== '' ? $text : ($item->text ?? '');
    }

    private function resolveMediaType($item, array $mediaMap): string
    {
        if (isset($item->article)) {
            return 'article';
        }

        $mediaKeys = $item->attachments->media_keys ?? [];

        if (empty($mediaKeys)) {
            return 'text';
        }

        return $mediaMap[$mediaKeys[0]]['type'] ?? 'unknown';
    }

    private function resolveMediaThumbnail($item, array $mediaMap): string
    {
        if ($coverMedia = $item->article->cover_media ?? null) {
            return $mediaMap[$coverMedia]['thumbnail'] ?? '';
        }

        $mediaKeys = $item->attachments->media_keys ?? [];

        if (empty($mediaKeys)) {
            return '';
        }

        return $mediaMap[$mediaKeys[0]]['thumbnail'] ?? '';
    }

    private function importPosts(array $items, array $mediaMap): void
    {
        $workspaceId = WorkspaceManager::current()->id;
        $accountId = $this->account->id;

        $data = Arr::map($items, function ($item) use ($workspaceId, $accountId, $mediaMap) {
            return [
                'workspace_id' => $workspaceId,
                'account_id' => $accountId,
                'provider_post_id' => $item->id,
                'text' => $this->resolveText($item),
                'url' => "https://x.com/i/status/{$item->id}",
                'thumbnail' => $this->resolveMediaThumbnail($item, $mediaMap),
                'content_type' => $this->resolveMediaType($item, $mediaMap),
                'created_at' => Carbon::parse($item->created_at, 'UTC')->toDateTimeString(),
            ];
        });

        ImportedPost::upsert($data, ['workspace_id', 'account_id', 'provider_post_id'], ['text', 'url', 'thumbnail', 'content_type']);
    }

    private function importPostInsights(array $items): void
    {
        $workspaceId = WorkspaceManager::current()->id;
        $accountId = $this->account->id;
        $today = Carbon::today('UTC')->toDateString();

        $insightData = [];
        $historyData = [];

        foreach ($items as $item) {
            $postId = $item->id;
            $public = $item->public_metrics ?? null;
            $nonPublic = $item->non_public_metrics ?? null;

            $metrics = [];

            if ($public) {
                $metrics[] = [TwitterPostInsightType::IMPRESSION_COUNT, (int) ($public->impression_count ?? 0)];
                $metrics[] = [TwitterPostInsightType::LIKE_COUNT, (int) ($public->like_count ?? 0)];
                $metrics[] = [TwitterPostInsightType::RETWEET_COUNT, (int) ($public->retweet_count ?? 0)];
                $metrics[] = [TwitterPostInsightType::REPLY_COUNT, (int) ($public->reply_count ?? 0)];
                $metrics[] = [TwitterPostInsightType::QUOTE_COUNT, (int) ($public->quote_count ?? 0)];
                $metrics[] = [TwitterPostInsightType::BOOKMARK_COUNT, (int) ($public->bookmark_count ?? 0)];
            }

            if ($nonPublic) {
                $metrics[] = [TwitterPostInsightType::URL_LINK_CLICKS, (int) ($nonPublic->url_link_clicks ?? 0)];
                $metrics[] = [TwitterPostInsightType::USER_PROFILE_CLICKS, (int) ($nonPublic->user_profile_clicks ?? 0)];
            }

            foreach ($metrics as [$type, $value]) {
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
            TwitterPostInsight::upsert(
                $insightData,
                ['workspace_id', 'account_id', 'provider_post_id', 'type'],
                ['value', 'updated_at']
            );

            TwitterPostInsightHistory::upsert(
                $historyData,
                ['workspace_id', 'account_id', 'provider_post_id', 'type', 'date'],
                ['value']
            );
        }
    }
}
