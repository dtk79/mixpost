<?php

namespace Inovector\Mixpost\SocialProviders\Bluesky\Concerns;

use Inovector\Mixpost\Features;
use Inovector\Mixpost\Jobs\ComputePostingTimeScoresJob;
use Inovector\Mixpost\SocialProviders\Bluesky\Jobs\ImportAccountFollowersJob;
use Inovector\Mixpost\SocialProviders\Bluesky\Jobs\ImportBlueskyPostsJob;
use Inovector\Mixpost\Support\JobSequence;

trait ManagesBlueskyJobs
{
    public static function initialJobs(): array
    {
        return [
            new JobSequence(
                [ImportAccountFollowersJob::class, ImportBlueskyPostsJob::class],
                [ComputePostingTimeScoresJob::class],
            ),
        ];
    }

    public static function highPriorityJobs(): array
    {
        return [
            ImportAccountFollowersJob::class,
            ImportBlueskyPostsJob::class,
        ];
    }

    public static function mediumPriorityJobs(): array
    {
        return [];
    }

    public static function lowPriorityJobs(): array
    {
        return [];
    }

    public static function dailyPriorityJobs(): array
    {
        return [ComputePostingTimeScoresJob::class];
    }
}
