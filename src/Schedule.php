<?php

namespace Inovector\Mixpost;

use Illuminate\Console\Scheduling\Schedule as LaravelSchedule;

class Schedule
{
    private const LOW_COST_STATS_PROVIDERS = 'facebook_page,facebook_group,instagram,bluesky,youtube';

    private const COST_CONTROLLED_STATS_PROVIDERS = 'twitter,mastodon';

    public static function register(LaravelSchedule $schedule): void
    {
        $schedule->command('mixpost:run-scheduled-posts')->everyMinute();
        $schedule->command('mixpost:import-account-data --providers='.self::LOW_COST_STATS_PROVIDERS)->everyThirtyMinutes();
        $schedule->command('mixpost:import-account-data --providers='.self::COST_CONTROLLED_STATS_PROVIDERS)->everyTwoHours();
        $schedule->command('mixpost:import-account-audience --providers='.self::LOW_COST_STATS_PROVIDERS)->everyThirtyMinutes();
        $schedule->command('mixpost:import-account-audience --providers='.self::COST_CONTROLLED_STATS_PROVIDERS)->everyThreeHours();
        $schedule->command('mixpost:process-metrics --providers='.self::LOW_COST_STATS_PROVIDERS)->everyThirtyMinutes();
        $schedule->command('mixpost:process-metrics --providers='.self::COST_CONTROLLED_STATS_PROVIDERS)->everyThreeHours();
        $schedule->command('mixpost:delete-old-data')->daily();
        $schedule->command('mixpost:prune-temporary-directory')->hourly();
    }
}
