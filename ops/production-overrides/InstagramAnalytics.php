<?php

namespace Inovector\Mixpost\Analytics;

use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inovector\Mixpost\Contracts\ProviderAnalytics;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Audience;
use Inovector\Mixpost\Models\ImportedPost;
use Inovector\Mixpost\SocialProviders\Meta\Enums\InstagramDemographicMetric;
use Inovector\Mixpost\SocialProviders\Meta\Enums\InstagramDemographicTimeframe;
use Inovector\Mixpost\SocialProviders\Meta\Enums\InstagramInsightType;
use Inovector\Mixpost\SocialProviders\Meta\Enums\InstagramPostInsightType;
use Inovector\Mixpost\SocialProviders\Meta\Models\InstagramCompetitor;
use Inovector\Mixpost\SocialProviders\Meta\Models\InstagramCompetitorSnapshot;
use Inovector\Mixpost\SocialProviders\Meta\Models\InstagramDemographic;
use Inovector\Mixpost\SocialProviders\Meta\Models\InstagramInsight;
use Inovector\Mixpost\SocialProviders\Meta\Models\InstagramInsightTotalValue;
use Inovector\Mixpost\SocialProviders\Meta\Models\InstagramPostInsight;
use Inovector\Mixpost\SocialProviders\Meta\Models\InstagramPostInsightHistory;

class InstagramAnalytics implements ProviderAnalytics
{
    use Concerns\HasHashtagAnalytics;
    use Concerns\HasInsights;
    use Concerns\HasMixpostPostLinks;
    use Concerns\HasPaginatedContent;
    use Concerns\HasPeriodComparison;
    use Concerns\HasPostInsightDeltas;

    protected string $startDate;

    protected string $endDate;

    protected bool $isCustomRange = false;

    public function __invoke(Account $account, string $type, string $period, ?string $dateFrom = null, ?string $dateTo = null, array $params = []): array
    {
        $this->resolveDates($period, $dateFrom, $dateTo);
        $this->contentParams = $params;

        return match ($type) {
            'overview' => $this->overview($account, $period),
            'engagement' => $this->engagement($account, $period),
            'audience' => $this->audience($account),
            'reach' => $this->reach($account, $period),
            'content' => $this->content($account),
            'hashtags' => $this->hashtags($account),
            'insights' => $this->insights($account),
            'competitors' => $this->competitors($account),
            default => [],
        };
    }

    public static function tabs(): array
    {
        return ['overview', 'engagement', 'audience', 'reach', 'content', 'hashtags', 'insights', 'competitors'];
    }

    public static function defaultTab(): string
    {
        return 'overview';
    }

    protected function overview(Account $account, string $period): array
    {
        $tvData = $this->getMetricCards($account);

        return [
            'metrics' => $tvData['metrics'],
            'follower_gains' => $this->timeSeries($account, InstagramInsightType::FOLLOWS),
            'follower_losses' => $this->timeSeries($account, InstagramInsightType::UNFOLLOWS),
            'audience' => $this->audienceChart($account),
        ];
    }

    protected function engagement(Account $account, string $period): array
    {
        $totals = $this->getTotalValues($account);

        return [
            'totals' => [
                'likes' => $this->totalValueSum($totals, InstagramInsightType::LIKES),
                'comments' => $this->totalValueSum($totals, InstagramInsightType::COMMENTS),
                'shares' => $this->totalValueSum($totals, InstagramInsightType::SHARES),
                'saves' => $this->totalValueSum($totals, InstagramInsightType::SAVES),
                'replies' => $this->totalValueSum($totals, InstagramInsightType::REPLIES),
                'reposts' => $this->totalValueSum($totals, InstagramInsightType::REPOSTS),
            ],
            'by_media_type' => [
                'post' => [
                    'likes' => $this->totalValueSum($totals, InstagramInsightType::LIKES_POST),
                    'comments' => $this->totalValueSum($totals, InstagramInsightType::COMMENTS_POST),
                    'shares' => $this->totalValueSum($totals, InstagramInsightType::SHARES_POST),
                    'saves' => $this->totalValueSum($totals, InstagramInsightType::SAVES_POST),
                    'total_interactions' => $this->totalValueSum($totals, InstagramInsightType::TOTAL_INTERACTIONS_POST),
                ],
                'reel' => [
                    'likes' => $this->totalValueSum($totals, InstagramInsightType::LIKES_REEL),
                    'comments' => $this->totalValueSum($totals, InstagramInsightType::COMMENTS_REEL),
                    'shares' => $this->totalValueSum($totals, InstagramInsightType::SHARES_REEL),
                    'saves' => $this->totalValueSum($totals, InstagramInsightType::SAVES_REEL),
                    'total_interactions' => $this->totalValueSum($totals, InstagramInsightType::TOTAL_INTERACTIONS_REEL),
                ],
                'story' => [
                    'likes' => $this->totalValueSum($totals, InstagramInsightType::LIKES_STORY),
                    'shares' => $this->totalValueSum($totals, InstagramInsightType::SHARES_STORY),
                    'total_interactions' => $this->totalValueSum($totals, InstagramInsightType::TOTAL_INTERACTIONS_STORY),
                ],
            ],
        ];
    }

    protected function audience(Account $account): array
    {
        $result = [];

        foreach ([InstagramDemographicTimeframe::THIS_MONTH, InstagramDemographicTimeframe::THIS_WEEK] as $timeframe) {
            $key = $timeframe === InstagramDemographicTimeframe::THIS_MONTH ? 'this_month' : 'this_week';

            $result[$key] = [
                'follower_demographics' => [
                    'age' => $this->demographics($account, InstagramDemographicMetric::FOLLOWER_DEMOGRAPHICS, 'age', $timeframe),
                    'gender' => $this->demographics($account, InstagramDemographicMetric::FOLLOWER_DEMOGRAPHICS, 'gender', $timeframe),
                    'country' => $this->demographics($account, InstagramDemographicMetric::FOLLOWER_DEMOGRAPHICS, 'country', $timeframe, 10),
                    'city' => $this->demographics($account, InstagramDemographicMetric::FOLLOWER_DEMOGRAPHICS, 'city', $timeframe, 10),
                ],
                'engaged_demographics' => [
                    'age' => $this->demographics($account, InstagramDemographicMetric::ENGAGED_AUDIENCE_DEMOGRAPHICS, 'age', $timeframe),
                    'gender' => $this->demographics($account, InstagramDemographicMetric::ENGAGED_AUDIENCE_DEMOGRAPHICS, 'gender', $timeframe),
                    'country' => $this->demographics($account, InstagramDemographicMetric::ENGAGED_AUDIENCE_DEMOGRAPHICS, 'country', $timeframe, 10),
                    'city' => $this->demographics($account, InstagramDemographicMetric::ENGAGED_AUDIENCE_DEMOGRAPHICS, 'city', $timeframe, 10),
                ],
            ];
        }

        return $result;
    }

    protected function reach(Account $account, string $period): array
    {
        $totals = $this->getTotalValues($account);

        return [
            'totals' => [
                'reach' => $this->totalValueSum($totals, InstagramInsightType::REACH),
                'views' => $this->totalValueSum($totals, InstagramInsightType::VIEWS),
            ],
            'by_follower_type' => [
                'reach_follower' => $this->totalValueSum($totals, InstagramInsightType::REACH_FOLLOWER),
                'reach_non_follower' => $this->totalValueSum($totals, InstagramInsightType::REACH_NON_FOLLOWER),
                'views_follower' => $this->totalValueSum($totals, InstagramInsightType::VIEWS_FOLLOWER),
                'views_non_follower' => $this->totalValueSum($totals, InstagramInsightType::VIEWS_NON_FOLLOWER),
            ],
            'by_media_type' => [
                'reach_post' => $this->totalValueSum($totals, InstagramInsightType::REACH_POST),
                'reach_reel' => $this->totalValueSum($totals, InstagramInsightType::REACH_REEL),
                'reach_story' => $this->totalValueSum($totals, InstagramInsightType::REACH_STORY),
                'views_post' => $this->totalValueSum($totals, InstagramInsightType::VIEWS_POST),
                'views_reel' => $this->totalValueSum($totals, InstagramInsightType::VIEWS_REEL),
                'views_story' => $this->totalValueSum($totals, InstagramInsightType::VIEWS_STORY),
            ],
            'reach_trend' => $this->timeSeries($account, InstagramInsightType::REACH),
        ];
    }

    protected function getMetricCards(Account $account): array
    {
        $totals = $this->getTotalValues($account);
        $nearestWindow = $this->getNearestWindowValues($account);

        return [
            'metrics' => [
                'accounts_engaged' => $this->totalValueNearest($nearestWindow, InstagramInsightType::ACCOUNTS_ENGAGED),
                'total_interactions' => $this->totalValueSum($totals, InstagramInsightType::TOTAL_INTERACTIONS),
                'views' => $this->totalValueSum($totals, InstagramInsightType::VIEWS),
                'reach' => $this->totalValueSum($totals, InstagramInsightType::REACH),
                'follows' => $this->totalValueSum($totals, InstagramInsightType::FOLLOWS),
                'unfollows' => $this->totalValueSum($totals, InstagramInsightType::UNFOLLOWS),
                'profile_links_taps' => $this->totalValueSum($totals, InstagramInsightType::PROFILE_LINKS_TAPS),
            ],
        ];
    }

    /**
     * Get total values by summing all 30-day windows that fall within the selected date range.
     * If the selected range is shorter than a stored window, use the nearest available
     * window ending by the range end so short dashboard periods do not render as zeros.
     */
    protected function getTotalValues(Account $account): Collection
    {
        $rows = InstagramInsightTotalValue::account($account->id)
            ->whereDate('date_start', '>=', $this->startDate)
            ->whereDate('date_end', '<=', $this->endDate)
            ->get();

        if ($rows->isEmpty()) {
            $rows = $this->getNearestWindowValues($account);
        }

        return $this->sumTotalValueRows($rows);
    }

    protected function sumTotalValueRows(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn ($row) => $row->getRawOriginal('type'))
            ->map(fn ($rows) => $rows->sum('value'));
    }

    /**
     * Get the nearest single 30-day window for metrics that can't be summed (e.g. accounts_engaged).
     */
    protected function getNearestWindowValues(Account $account): Collection
    {
        // Find the most recent window that ends within our date range
        $window = InstagramInsightTotalValue::account($account->id)
            ->whereDate('date_end', '<=', $this->endDate)
            ->orderByDesc('date_end')
            ->first();

        if (! $window) {
            return collect();
        }

        return InstagramInsightTotalValue::account($account->id)
            ->where('date_start', $window->date_start)
            ->where('date_end', $window->date_end)
            ->get();
    }

    protected function buildYourAccount(Account $account, int $followers): array
    {
        $mediaCount = ImportedPost::where('account_id', $account->id)->count();

        $avgEngagement = 0;

        if ($mediaCount > 0) {
            // Sum likes + comments from media insights
            $engagementTypes = [
                InstagramPostInsightType::LIKES->value,
                InstagramPostInsightType::COMMENTS->value,
            ];

            $model = $this->postInsightModel();

            $totalEngagement = $model::account($account->id)
                ->whereIn('type', $engagementTypes)
                ->sum('value');

            $avgEngagement = (int) round($totalEngagement / $mediaCount);
        }

        return [
            'name' => $account->name,
            'followers_count' => $followers,
            'media_count' => $mediaCount,
            'avg_engagement' => $avgEngagement,
        ];
    }

    protected function totalValueSum(Collection $totals, InstagramInsightType $type): int
    {
        return (int) ($totals->get($type->value) ?? 0);
    }

    protected function totalValueNearest(Collection $totals, InstagramInsightType $type): int
    {
        $row = $totals->firstWhere('type', $type);

        return $row ? (int) $row->value : 0;
    }

    protected function timeSeries(Account $account, InstagramInsightType $type): array
    {
        $rows = InstagramInsight::account($account->id)
            ->where('type', $type)
            ->whereDate('date', '>=', $this->startDate)
            ->whereDate('date', '<=', $this->endDate)
            ->orderBy('date', 'asc')
            ->pluck('value', 'date');

        $labels = [];
        $values = [];
        $prevMonth = null;

        foreach ($this->eachDay() as $date) {
            $labels[] = $this->formatDateLabel($date, $prevMonth);
            $prevMonth = $date->translatedFormat('M');
            $values[] = (int) ($rows[$date->toDateString()] ?? 0);
        }

        return compact('labels', 'values');
    }

    protected function demographics(Account $account, InstagramDemographicMetric $metric, string $breakdown, InstagramDemographicTimeframe $timeframe, ?int $limit = null): array
    {
        $query = InstagramDemographic::account($account->id)
            ->metric($metric)
            ->timeframe($timeframe)
            ->breakdown($breakdown)
            ->select('dimension_key', DB::raw('SUM(value) as total'))
            ->groupBy('dimension_key')
            ->orderByDesc('total');

        if ($limit) {
            $query->limit($limit);
        }

        $results = $query->get();

        return [
            'labels' => $results->pluck('dimension_key')->all(),
            'values' => $results->pluck('total')->map(fn ($v) => (int) $v)->all(),
        ];
    }

    protected function audienceChart(Account $account, string $column = 'total'): array
    {
        $report = Audience::account($account->id)
            ->select('date', DB::raw("SUM({$column}) as value"))
            ->groupBy('date')
            ->whereDate('date', '>=', $this->startDate)
            ->whereDate('date', '<=', $this->endDate)
            ->orderBy('date', 'asc')
            ->pluck('value', 'date');

        $labels = [];
        $values = [];
        $prevMonth = null;

        foreach ($this->eachDay() as $date) {
            $val = $report->get($date->toDateString());
            $labels[] = $this->formatDateLabel($date, $prevMonth);
            $prevMonth = $date->translatedFormat('M');
            $values[] = $val !== null ? (int) $val : null;
        }

        return compact('labels', 'values');
    }

    protected function eachDay(): CarbonPeriod
    {
        return CarbonPeriod::create(
            Carbon::parse($this->startDate, 'UTC'),
            Carbon::parse($this->endDate, 'UTC')
        );
    }

    protected function postInsightModel(): string
    {
        return InstagramPostInsight::class;
    }

    protected function historyModel(): string
    {
        return InstagramPostInsightHistory::class;
    }

    protected function engagementTypes(): array
    {
        return [
            InstagramPostInsightType::LIKES->value,
            InstagramPostInsightType::COMMENTS->value,
            InstagramPostInsightType::SHARES->value,
            InstagramPostInsightType::SAVES->value,
        ];
    }

    protected function viewsType(): int
    {
        return InstagramPostInsightType::VIEWS->value;
    }

    protected function hashtagMetricBreakdown(): array
    {
        return [
            InstagramPostInsightType::LIKES->value => 'likes',
            InstagramPostInsightType::COMMENTS->value => 'comments',
            InstagramPostInsightType::SHARES->value => 'shares',
            InstagramPostInsightType::SAVES->value => 'saves',
        ];
    }

    protected function competitors(Account $account): array
    {
        $competitors = InstagramCompetitor::account($account->id)
            ->orderBy('followers_count', 'desc')
            ->get();

        if ($competitors->isEmpty()) {
            return ['competitors' => [], 'your_account' => null];
        }

        // Your account's current followers
        $yourFollowers = (int) Audience::account($account->id)
            ->orderByDesc('date')
            ->value('total') ?? 0;

        // Build leaderboard with follower growth trends
        $competitorData = [];

        foreach ($competitors as $competitor) {
            $snapshots = InstagramCompetitorSnapshot::where('competitor_id', $competitor->id)
                ->whereDate('date', '>=', $this->startDate)
                ->whereDate('date', '<=', $this->endDate)
                ->orderBy('date', 'asc')
                ->get();

            $followerTrend = [];
            $labels = [];
            $prevMonth = null;

            $snapshotsByDate = $snapshots->keyBy(fn ($s) => $s->date->toDateString());

            foreach ($this->eachDay() as $date) {
                $snap = $snapshotsByDate->get($date->toDateString());
                $labels[] = $this->formatDateLabel($date, $prevMonth);
                $prevMonth = $date->translatedFormat('M');
                $followerTrend[] = $snap ? $snap->followers_count : null;
            }

            // Top media by engagement
            $topMedia = $competitor->competitorMedia()
                ->orderByRaw('(like_count + comments_count) DESC')
                ->limit(12)
                ->get()
                ->map(fn ($m) => [
                    'caption' => $m->caption,
                    'permalink' => $m->permalink,
                    'media_type' => $m->media_type,
                    'like_count' => $m->like_count,
                    'comments_count' => $m->comments_count,
                    'engagement' => $m->like_count + $m->comments_count,
                    'timestamp' => $m->timestamp?->toDateString(),
                ])
                ->all();

            // Avg engagement per post
            $totalMedia = $competitor->competitorMedia()->count();
            $totalEngagement = $competitor->competitorMedia()->sum(DB::raw('like_count + comments_count'));
            $avgEngagement = $totalMedia > 0 ? round($totalEngagement / $totalMedia) : 0;

            $competitorData[] = [
                'id' => $competitor->id,
                'username' => $competitor->username,
                'name' => $competitor->name,
                'image' => $competitor->media ? Storage::disk($competitor->media['disk'])->url($competitor->media['path']) : null,
                'followers_count' => $competitor->followers_count,
                'media_count' => $competitor->media_count,
                'avg_engagement' => $avgEngagement,
                'follower_trend' => [
                    'labels' => $labels,
                    'values' => $followerTrend,
                ],
                'top_media' => $topMedia,
            ];
        }

        return [
            'competitors' => $competitorData,
            'your_account' => $this->buildYourAccount($account, $yourFollowers),
        ];
    }
}
