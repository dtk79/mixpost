<?php

namespace Inovector\Mixpost\SocialProviders\Meta\Concerns;

use Inovector\Mixpost\Jobs\ComputePostingTimeScoresJob;
use Inovector\Mixpost\SocialProviders\Meta\Jobs\BackfillInstagramAudienceJob;
use Inovector\Mixpost\SocialProviders\Meta\Jobs\ImportInstagramAllDemographicsJob;
use Inovector\Mixpost\SocialProviders\Meta\Jobs\ImportInstagramAllTimeInsightsTimeSeriesJob;
use Inovector\Mixpost\SocialProviders\Meta\Jobs\ImportInstagramAudienceJob;
use Inovector\Mixpost\SocialProviders\Meta\Jobs\ImportInstagramCompetitorsJob;
use Inovector\Mixpost\SocialProviders\Meta\Jobs\ImportInstagramInsightsFollowTypeBreakdownJob;
use Inovector\Mixpost\SocialProviders\Meta\Jobs\ImportInstagramInsightsMediaProductTypeBreakdownJob;
use Inovector\Mixpost\SocialProviders\Meta\Jobs\ImportInstagramInsightsTimeSeriesJob;
use Inovector\Mixpost\SocialProviders\Meta\Jobs\ImportInstagramInsightsTotalValueAllPeriodsJob;
use Inovector\Mixpost\SocialProviders\Meta\Jobs\ImportInstagramInsightsTotalValueJob;
use Inovector\Mixpost\SocialProviders\Meta\Jobs\ImportInstagramMediaJob;
use Inovector\Mixpost\SocialProviders\Meta\Jobs\ImportInstagramStoriesJob;
use Inovector\Mixpost\Support\JobSequence;

trait ManagesInstagramJobs
{
    /**
     * Initial jobs - run when account is first connected
     * Import historical data and establish baseline metrics
     */
    public static function initialJobs(): array
    {
        return [
            ImportInstagramInsightsTotalValueJob::class,
            ImportInstagramInsightsMediaProductTypeBreakdownJob::class,
            ImportInstagramInsightsFollowTypeBreakdownJob::class,
            ImportInstagramInsightsTotalValueAllPeriodsJob::class,
            ImportInstagramAllDemographicsJob::class,
            new JobSequence(
                [ImportInstagramAllTimeInsightsTimeSeriesJob::class, ImportInstagramAudienceJob::class],
                [BackfillInstagramAudienceJob::class],
            ),
            new JobSequence(
                [ImportInstagramMediaJob::class],
                [ComputePostingTimeScoresJob::class],
            ),
            ImportInstagramStoriesJob::class,
        ];
    }

    /**
     * High priority jobs - run every hour
     * Critical metrics that need frequent updates
     */
    public static function highPriorityJobs(): array
    {
        return [];
    }

    /**
     * Medium priority jobs - run every 3 hours
     * Standard metrics updated regularly
     */
    public static function mediumPriorityJobs(): array
    {
        return [
            ImportInstagramStoriesJob::class,
            ImportInstagramInsightsTotalValueJob::class,
            ImportInstagramMediaJob::class,
            ImportInstagramAudienceJob::class,
        ];
    }

    /**
     * Low priority jobs - run every 6 hours
     * Historical or less critical metrics
     */
    public static function lowPriorityJobs(): array
    {
        return [];
    }

    /**
     * Daily jobs - run once per day
     * Aggregations, cleanup, and comprehensive reports
     */
    public static function dailyPriorityJobs(): array
    {
        return [
            ImportInstagramInsightsTotalValueJob::class,
            ImportInstagramInsightsMediaProductTypeBreakdownJob::class,
            ImportInstagramInsightsFollowTypeBreakdownJob::class,
            ImportInstagramInsightsTotalValueAllPeriodsJob::class,
            ImportInstagramAllDemographicsJob::class,
            new JobSequence(
                [ImportInstagramInsightsTimeSeriesJob::class, ImportInstagramAudienceJob::class],
                [BackfillInstagramAudienceJob::class],
            ),
            new JobSequence(
                [ImportInstagramMediaJob::class],
                [ComputePostingTimeScoresJob::class],
            ),
            ImportInstagramStoriesJob::class,
            ImportInstagramCompetitorsJob::class,
        ];
    }
}
