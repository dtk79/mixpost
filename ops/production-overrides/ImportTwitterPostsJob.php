<?php

namespace Inovector\Mixpost\SocialProviders\Twitter\Jobs;

use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Inovector\Mixpost\Concerns\Job\OnAnalyticsQueue;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Jobs\SocialProviderJob;
use Inovector\Mixpost\Models\ImportedPost;
use Inovector\Mixpost\SocialProviders\Twitter\Enums\TwitterPostInsightType;
use Inovector\Mixpost\SocialProviders\Twitter\Enums\TwitterAnalyticsRange;
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

        if ($provider->getTier() === 'free' || ! $provider::supportAnalytics()) {
            return new SocialProviderResponse(SocialProviderResponseStatus::OK, []);
        }

        $startTime = $this->startTime($provider);
        $endTime = $this->options['timeline_end_time'] ?? null;

        // A historical cadence wholly outside a restricted range must not spend an X read.
        if ($startTime && $endTime && Carbon::parse($endTime)->lte(Carbon::parse($startTime))) {
            return new SocialProviderResponse(SocialProviderResponseStatus::OK, []);
        }

        // Carry the effective floor through pagination; each page rechecks current limits.
        $this->options['timeline_start_time'] = $startTime;

        return $provider->getUserTweetTimeline(
            $this->account->provider_id,
            $this->options['pagination_next_token'] ?? '',
            ! ($this->options['public_metrics_only'] ?? false),
            $startTime,
            $endTime
        );
    }

    private function startTime(TwitterProvider $provider): ?string
    {
        $range = $provider::analyticsRange();
        $explicitWindow = array_key_exists('timeline_start_time', $this->options);
        $startTime = $explicitWindow
            ? $this->options['timeline_start_time']
            : ($this->options['start_time'] ?? null);

        // FULL retains our explicit age-tiered windows, including history older than 90 days.
        // Unwindowed imports use the vendor's initial/daily defaults.
        if ($range === TwitterAnalyticsRange::FULL && ($explicitWindow || array_key_exists('start_time', $this->options))) {
            return $startTime;
        }

        $days = $range->days(initialImport: $this->batch() !== null);
        if ($days === null) {
            return $startTime;
        }

        $floor = Carbon::now('UTC')->subDays($days);

        return $startTime && Carbon::parse($startTime)->gte($floor)
            ? $startTime
            : $floor->toIso8601ZuluString();
    }

    protected function processResponse(SocialProviderResponse $response): void
    {
        $context = $response->context();
        $items = $context['data'] ?? [];

        if (empty($items)) {
            return;
        }

        $existing = ImportedPost::query()
            ->where('workspace_id', WorkspaceManager::current()->id)
            ->where('account_id', $this->account->id)
            ->whereIn('provider_post_id', array_map(fn ($item) => $item->id, $items))
            ->get(['provider_post_id', 'thumbnail', 'content_type'])
            ->keyBy('provider_post_id');
        $mediaMap = $this->fetchMissingMedia($items, $existing);

        $this->importPosts($items, $mediaMap, $existing);
        $this->importPostInsights($items);

        $meta = $context['meta'] ?? null;

        if ($meta && isset($meta->next_token)) {
            $options = array_merge($this->options, [
                'pagination_next_token' => $meta->next_token,
                'public_metrics_only' => $this->reachedPublicMetricsOnly($items),
            ]);
            $this->dispatchOrAddToBatch((new self($this->account, $options))->delay(60));
        }
    }

    private function fetchMissingMedia(array $items, Collection $existing): array
    {
        $missing = [];
        foreach ($items as $item) {
            // Article body images are not used by the imported-post preview.
            $hasMedia = ! empty($item->attachments->media_keys)
                || ! empty($item->article->cover_media);
            $cached = $existing->get($item->id);
            if ($hasMedia && (! $cached || ! $cached->thumbnail
                || ! in_array($cached->content_type, ['photo', 'video', 'animated_gif', 'article'], true))) {
                $missing[] = $item->id;
            }
        }

        $map = [];
        foreach (array_chunk(array_values(array_unique($missing)), 100) as $ids) {
            try {
                $response = $this->connectProvider($this->account)->getTweetMedia($ids);
                if ($response->hasError()) {
                    Log::warning('mixpost.twitter_media_metadata_unavailable', [
                        'account_id' => $this->account->id,
                        'post_count' => count($ids),
                        'status' => $response->status()->value,
                    ]);
                    continue;
                }
                $map = array_replace($map, $this->buildMediaMap($response->context()['includes'] ?? null));
            } catch (\Illuminate\Http\Client\ConnectionException|\Abraham\TwitterOAuth\TwitterOAuthException $error) {
                // Missing metadata is retried on the next scheduled refresh;
                // it must not discard metrics or erase an existing thumbnail.
                Log::warning('mixpost.twitter_media_metadata_connection_failed', [
                    'account_id' => $this->account->id, 'post_count' => count($ids),
                ]);
            }
        }

        return $map;
    }

    // The timeline is newest first, so once a page ends past the 30-day window every later page does too.
    private function reachedPublicMetricsOnly(array $items): bool
    {
        if ($this->options['public_metrics_only'] ?? false) {
            return true;
        }

        $oldest = end($items)->created_at ?? null;

        return $oldest && Carbon::parse($oldest, 'UTC')->lt(Carbon::now('UTC')->subDays(30));
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
            $noteText = trim((string) ($item->note_tweet->text ?? ''));

            return $noteText !== '' ? $noteText : ($item->text ?? '');
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

    private function importPosts(array $items, array $mediaMap, Collection $existing): void
    {
        $workspaceId = WorkspaceManager::current()->id;
        $accountId = $this->account->id;

        $data = Arr::map($items, function ($item) use ($workspaceId, $accountId, $mediaMap, $existing) {
            $cached = $existing->get($item->id);
            $type = $this->resolveMediaType($item, $mediaMap);
            $thumbnail = $this->resolveMediaThumbnail($item, $mediaMap);

            return [
                'workspace_id' => $workspaceId,
                'account_id' => $accountId,
                'provider_post_id' => $item->id,
                'text' => $this->resolveText($item),
                'url' => "https://x.com/i/status/{$item->id}",
                'thumbnail' => ($cached->thumbnail ?? '') !== '' ? $cached->thumbnail : $thumbnail,
                'content_type' => $cached && in_array($cached->content_type, ['photo', 'video', 'animated_gif', 'article'], true)
                    ? $cached->content_type : $type,
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
