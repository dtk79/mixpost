<?php
namespace Inovector\Mixpost\SocialProviders\Twitter\Concerns;

use Inovector\Mixpost\Features;
use Inovector\Mixpost\Jobs\ComputePostingTimeScoresJob;
use Inovector\Mixpost\SocialProviders\Twitter\Jobs\ImportTwitterFollowersJob;
use Inovector\Mixpost\SocialProviders\Twitter\Jobs\ImportTwitterPostsJob;
use Inovector\Mixpost\Support\JobSequence;

trait ManagesTwitterJobs

{
    public static function initialJobs(): array
    {
        if (! static::supportAnalytics()) {
            return [];
        }

        return [ImportTwitterFollowersJob::class, ComputePostingTimeScoresJob::class];
    }

    public static function highPriorityJobs(): array
    {
        if (! static::supportAnalytics()) {
            return [];
        }

        return [
            ImportTwitterFollowersJob::class,
        ];
    }

    // 2026-09-12: ImportTwitterNotificationsJob was ENABLED here for one day and then REVERTED
    // (Dan's call). Keeping the record so nobody re-runs the experiment blind.
    //
    // It works: Mixpost ships the job, it is listed in none of these arrays upstream, and adding it
    // here imported 498 X replies + @-mentions across 8 accounts in a 7-day window via one
    // recent-search request per account, incrementally by since_id, into mixpost_inbox_*.
    //
    // ⚠ IT WAS NOT WORTH IT, and the reason is YIELD, not price. Of those 498 messages, only SIX
    // attached to a tracked campaign placement (~$1 each at X's $0.005/post + $0.010/user-resource
    // rate, ≈$5.71 for the backfill, ≈$25-32/month ongoing). conversation_id only links a reply to a
    // placement when the thread root is a tracked post, and our accounts' replies are overwhelmingly
    // about non-campaign content. Restricting to the 4 accounts that hold campaign placements
    // removes 57% of the spend but leaves the ratio just as poor.
    // For contrast: Instagram yielded 1,386 responses at zero cost (Meta webhook + a token Mixpost
    // already holds).
    //
    // ⚠ Mention sentiment on X is UNAFFECTED and still free — a tweet's own text is already stored.
    // ⚠ If this is ever re-enabled: Horizon workers cache this trait, so
    // `php artisan horizon:terminate` is required or the change does nothing.
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
        if (! static::supportAnalytics()) {
            return [];
        }

        return [ComputePostingTimeScoresJob::class];
    }
}
