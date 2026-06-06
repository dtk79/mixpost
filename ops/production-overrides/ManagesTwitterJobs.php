<?php
namespace Inovector\Mixpost\SocialProviders\Twitter\Concerns;

use Inovector\Mixpost\SocialProviders\Twitter\Jobs\ImportTwitterFollowersJob;
use Inovector\Mixpost\SocialProviders\Twitter\Jobs\ImportTwitterPostsJob;

trait ManagesTwitterJobs

{
    public static function initialJobs(): array
    {
        return [
            ImportTwitterFollowersJob::class,
            ImportTwitterPostsJob::class,
        ];
    }

    public static function highPriorityJobs(): array
    {
        return [
            ImportTwitterFollowersJob::class,
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
        return [];
    }
}
