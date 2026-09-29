<?php

namespace Inovector\Mixpost\Analytics\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inovector\Mixpost\Facades\Settings;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Audience;
use Inovector\Mixpost\Models\ImportedPost;

trait HasInsights
{
    protected const MIN_POSTS_FOR_REACH_FLOOR = 5;

    protected const REACH_FLOOR_RATIO = 0.3;

    /**
     * Return the post insight model class (e.g., TwitterPostInsight::class).
     */
    abstract protected function postInsightModel(): string;

    /**
     * Return enum cases (as int values) that count as "engagement".
     * e.g., [TwitterPostInsightType::LIKE_COUNT->value, ...]
     */
    abstract protected function engagementTypes(): array;

    /**
     * Return the enum case (as int value) that represents "views/impressions".
     * e.g., TwitterPostInsightType::IMPRESSION_COUNT->value
     *
     * Null when the provider exposes no reach metric at all — the engagement rate is then taken
     * against the follower count instead. Never point this at a metric that is itself engagement:
     * the rate would carry its own denominator and could not drop below 100%.
     */
    abstract protected function viewsType(): ?int;

    /**
     * @deprecated Use content_type column on ImportedPost instead.
     */
    protected function mediaTypeField(): string
    {
        return 'content_type';
    }

    /**
     * Return column name for post ID in the insight model.
     */
    protected function insightPostIdColumn(): string
    {
        return 'provider_post_id';
    }

    /**
     * Per-post engagement rates for an explicit range, for consumers outside the analytics tab.
     */
    public function engagementRates(Account $account, string $startDate, string $endDate): Collection
    {
        $posts = $this->importedPostsInRange($account, $startDate, $endDate);

        if ($posts->isEmpty()) {
            return collect();
        }

        $insightsByPost = $this->getPostInsightsGrouped($account, $posts->pluck('provider_post_id')->all());

        return $this->withoutLowReachPosts($this->calculateEngagementRates($account, $posts, $insightsByPost));
    }

    protected function insights(Account $account): array
    {
        $posts = $this->analyticsPostsQuery($account)
            ->whereDate('created_at', '>=', $this->startDate)
            ->whereDate('created_at', '<=', $this->endDate)
            ->orderByDesc('created_at')
            ->get();

        if ($posts->isEmpty()) {
            return [];
        }

        $postIds = $posts->pluck('provider_post_id')->all();
        $insightsByPost = $this->getPostInsightsGrouped($account, $postIds);
        $postsWithRates = $this->calculateEngagementRates($account, $posts, $insightsByPost);

        if ($postsWithRates->isEmpty()) {
            return [];
        }

        return [
            'engagement_rate_basis' => $this->viewsType() === null ? 'followers' : 'views',
            'best_day' => $this->bestDay($postsWithRates),
            'best_time' => $this->bestTime($posts, $postsWithRates),
            'best_type' => $this->bestType($postsWithRates),
            'best_frequency' => $this->bestFrequency($posts, $postsWithRates),
            'top_post' => $this->topPost($postsWithRates),
            'engagement_trend' => $this->engagementTrend($postsWithRates),
            'posting_consistency' => $this->postingConsistency($posts),
        ];
    }

    /**
     * Posts of this account ranked by engagement rate, for callers that need the ranking on its
     * own rather than the whole insight bundle.
     */
    public function topEngagingPosts(Account $account, string $startDate, string $endDate, int $limit = 3): Collection
    {
        $posts = $this->analyticsPostsQuery($account)
            ->whereDate('created_at', '>=', $startDate)
            ->whereDate('created_at', '<=', $endDate)
            ->orderByDesc('created_at')
            ->get();

        if ($posts->isEmpty()) {
            return collect();
        }

        $insightsByPost = $this->getPostInsightsGrouped($account, $posts->pluck('provider_post_id')->all());

        return $this->withoutLowReachPosts($this->calculateEngagementRates($account, $posts, $insightsByPost))
            ->sortByDesc('engagement_rate')
            ->take($limit)
            ->values();
    }

    /**
     * Bounded with datetimes rather than whereDate() so the (workspace, account, created_at)
     * index is usable: wrapping the column in DATE() would force a scan. The scoring window is
     * a year wide, which is where that matters.
     */
    private function importedPostsInRange(Account $account, string $startDate, string $endDate): Collection
    {
        return $this->analyticsPostsQuery($account)
            ->whereBetween('created_at', [
                Carbon::parse($startDate, 'UTC')->startOfDay(),
                Carbon::parse($endDate, 'UTC')->endOfDay(),
            ])
            ->orderByDesc('created_at')
            ->get();
    }

    private function analyticsPostsQuery(Account $account)
    {
        $query = ImportedPost::where('account_id', $account->id);

        if ($account->provider === 'twitter') {
            $query->where(function ($query) {
                $query->whereNull('data->analytics_visible')
                    ->orWhere('data->analytics_visible', true);
            });
        }

        return $query;
    }

    /**
     * A rate is a ratio, so the posts nobody saw win it: three views and two likes beat a post
     * that reached a hundred thousand. Anything far below the account's usual reach is dropped
     * before ranking, and only once there are enough posts for a median to mean anything.
     */
    private function withoutLowReachPosts(Collection $posts): Collection
    {
        if ($this->viewsType() === null || $posts->count() < self::MIN_POSTS_FOR_REACH_FLOOR) {
            return $posts;
        }

        $floor = $posts->median('views') * self::REACH_FLOOR_RATIO;

        return $posts->filter(fn ($post) => $post->views >= $floor);
    }

    private function getPostInsightsGrouped(Account $account, array $postIds): Collection
    {
        $model = $this->postInsightModel();
        $column = $this->insightPostIdColumn();

        return $model::account($account->id)
            ->whereIn($column, $postIds)
            ->get()
            ->groupBy($column);
    }

    private function calculateEngagementRates(Account $account, Collection $posts, Collection $insightsByPost): Collection
    {
        $engagementTypes = $this->engagementTypes();
        $viewsType = $this->viewsType();

        // Providers without a reach metric divide by the audience instead. One query, not one per
        // post: the follower count is the same denominator for every post in the window.
        $followers = $viewsType === null ? $this->latestFollowerCount($account) : 0;

        return $posts->map(function ($post) use ($insightsByPost, $engagementTypes, $viewsType, $followers) {
            $postInsights = $insightsByPost->get($post->provider_post_id, collect());

            if ($postInsights->isEmpty()) {
                return null;
            }

            $engagement = $postInsights
                ->filter(fn ($i) => in_array($i->getRawOriginal('type'), $engagementTypes))
                ->sum('value');

            $views = $viewsType !== null
                ? (int) ($postInsights->first(fn ($i) => $i->getRawOriginal('type') === $viewsType)?->value ?? 0)
                : 0;

            $denominator = $viewsType !== null ? $views : $followers;

            if ($denominator <= 0) {
                return null;
            }

            return (object) [
                'provider_post_id' => $post->provider_post_id,
                'text' => $post->text,
                'url' => $post->url,
                'thumbnail' => $post->thumbnail,
                'content_type' => $post->content_type,
                'data' => $post->data,
                'created_at' => $post->created_at,
                'engagement' => $engagement,
                'views' => $views,
                'engagement_rate' => round(($engagement / $denominator) * 100, 2),
            ];
        })->filter();
    }

    private function latestFollowerCount(Account $account): int
    {
        return (int) Audience::account($account->id)
            ->orderByDesc('date')
            ->value('total');
    }

    private function bestDay(Collection $posts): ?array
    {
        $timezone = Settings::get('timezone');

        $byDay = $posts->groupBy(fn ($p) => $p->created_at->copy()->setTimezone($timezone)->dayOfWeek);

        // Include all 7 days, defaulting to 0 for days with no posts
        $avgByDay = collect();

        for ($i = 0; $i <= 6; $i++) {
            $group = $byDay->get($i);
            $avgByDay[$i] = $group ? round($group->avg('engagement_rate'), 2) : 0;
        }

        $postsWithData = $avgByDay->filter(fn ($v) => $v > 0);

        if ($postsWithData->isEmpty()) {
            return null;
        }

        $bestDayNum = $postsWithData->sortDesc()->keys()->first();
        $overallAvg = $posts->avg('engagement_rate');
        $bestAvg = $avgByDay[$bestDayNum];

        return [
            'day' => $bestDayNum,
            'day_name' => Carbon::now()->startOfWeek(Carbon::SUNDAY)->addDays($bestDayNum)->translatedFormat('l'),
            'engagement_rate' => $bestAvg,
            'vs_average' => $overallAvg > 0 ? round((($bestAvg - $overallAvg) / $overallAvg) * 100, 1) : 0,
            'all_days' => $avgByDay->sortKeys()->all(),
        ];
    }

    private function bestTime(Collection $allPosts, Collection $postsWithRates): ?array
    {
        $ratesByPostId = $postsWithRates->keyBy('provider_post_id');
        $timezone = Settings::get('timezone');

        $byHour = $allPosts
            ->filter(fn ($p) => $ratesByPostId->has($p->provider_post_id))
            ->groupBy(fn ($p) => $p->created_at->copy()->setTimezone($timezone)->hour);

        $avgByHour = $byHour->map(function ($group) use ($ratesByPostId) {
            $rates = $group->map(fn ($p) => $ratesByPostId[$p->provider_post_id]->engagement_rate);

            return round($rates->avg(), 2);
        });

        if ($avgByHour->isEmpty()) {
            return null;
        }

        $bestHour = $avgByHour->sortDesc()->keys()->first();

        return [
            'hour' => $bestHour,
            'hour_label' => sprintf('%02d:00', $bestHour),
            'engagement_rate' => $avgByHour[$bestHour],
            'all_hours' => $avgByHour->sortKeys()->all(),
        ];
    }

    private function bestType(Collection $posts): ?array
    {
        $byType = $posts->groupBy(fn ($p) => $p->content_type ?? 'unknown');

        $avgByType = $byType->map(fn ($group) => round($group->avg('engagement_rate'), 2));

        if ($avgByType->isEmpty()) {
            return null;
        }

        $bestType = $avgByType->sortDesc()->keys()->first();

        return [
            'type' => $bestType,
            'engagement_rate' => $avgByType[$bestType],
            'all_types' => $avgByType->all(),
        ];
    }

    private function bestFrequency(Collection $allPosts, Collection $postsWithRates): ?array
    {
        $ratesByPostId = $postsWithRates->keyBy('provider_post_id');

        $postsByDate = $allPosts->groupBy(fn ($p) => $p->created_at->toDateString());

        $frequencyData = [];

        foreach ($postsByDate as $date => $datePosts) {
            $count = $datePosts->count();

            $rates = $datePosts
                ->filter(fn ($p) => $ratesByPostId->has($p->provider_post_id))
                ->map(fn ($p) => $ratesByPostId[$p->provider_post_id]->engagement_rate);

            if ($rates->isNotEmpty()) {
                $frequencyData[] = (object) [
                    'count' => $count,
                    'avg_rate' => $rates->avg(),
                ];
            }
        }

        if (empty($frequencyData)) {
            return null;
        }

        $byFrequency = collect($frequencyData)->groupBy('count');

        $avgByFrequency = $byFrequency->map(fn ($group) => round($group->avg('avg_rate'), 2));

        $bestFreq = $avgByFrequency->sortDesc()->keys()->first();

        return [
            'frequency' => $bestFreq,
            'engagement_rate' => $avgByFrequency[$bestFreq],
            'all_frequencies' => $avgByFrequency->sortKeys()->all(),
        ];
    }

    private function topPost(Collection $posts): ?array
    {
        $top = $this->withoutLowReachPosts($posts)->sortByDesc('engagement_rate')->first();

        if (! $top) {
            return null;
        }

        return [
            'provider_post_id' => $top->provider_post_id,
            'text' => $top->text ?? '',
            'url' => $top->url ?? '',
            'thumbnail' => ImportedPost::thumbnailUrl($top->thumbnail ?? null),
            'content_type' => $top->content_type ?? null,
            'engagement_rate' => $top->engagement_rate,
            'engagement' => $top->engagement,
            'views' => $top->views,
            'created_at' => $top->created_at?->toDateString(),
        ];
    }

    private function engagementTrend(Collection $posts): ?array
    {
        if ($posts->count() < 2) {
            return null;
        }

        $midpoint = $posts->count() / 2;
        $sorted = $posts->sortBy('created_at')->values();

        $firstHalf = $sorted->take((int) $midpoint);
        $secondHalf = $sorted->skip((int) $midpoint);

        $firstAvg = $firstHalf->avg('engagement_rate');
        $secondAvg = $secondHalf->avg('engagement_rate');

        $change = $firstAvg > 0
            ? round((($secondAvg - $firstAvg) / $firstAvg) * 100, 1)
            : ($secondAvg > 0 ? 100 : 0);

        return [
            'direction' => $change >= 0 ? 'up' : 'down',
            'change' => $change,
            'first_half_avg' => round($firstAvg, 2),
            'second_half_avg' => round($secondAvg, 2),
        ];
    }

    private function postingConsistency(Collection $posts): array
    {
        $start = Carbon::parse($this->startDate, 'UTC');
        $end = Carbon::parse($this->endDate, 'UTC');
        $totalDays = $start->diffInDays($end) + 1;

        $daysPosted = $posts->groupBy(fn ($p) => $p->created_at->toDateString())->count();
        $totalPosts = $posts->count();

        return [
            'days_posted' => $daysPosted,
            'total_days' => $totalDays,
            'total_posts' => $totalPosts,
            'avg_posts_per_day' => $totalDays > 0 ? round($totalPosts / $totalDays, 1) : 0,
            'percentage' => $totalDays > 0 ? round(($daysPosted / $totalDays) * 100, 1) : 0,
        ];
    }
}
