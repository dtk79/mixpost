<?php

namespace Inovector\Mixpost;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Inovector\Mixpost\Commands\Workspace\CheckAndRefreshAccountTokenCommand;
use Inovector\Mixpost\Commands\Workspace\PruneTrashedPostsCommand;
use Inovector\Mixpost\Commands\Workspace\RunAccountProviderJobsCommand;
use Inovector\Mixpost\Commands\Workspace\RunScheduledPostsCommand;
use Inovector\Mixpost\Jobs\WorkspaceArtisanJob;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\WebhookDelivery;
use Inovector\Mixpost\Models\Workspace;
use Inovector\Mixpost\SocialProviders\Google\Jobs\ImportYoutubeVideosJob;
use Inovector\Mixpost\SocialProviders\Meta\Jobs\ImportFacebookPagePostsJob;
use Inovector\Mixpost\SocialProviders\Meta\Jobs\ImportFacebookPostInsightsJob;
use Inovector\Mixpost\SocialProviders\Meta\Jobs\ImportInstagramAudienceJob;
use Inovector\Mixpost\SocialProviders\Meta\Jobs\ImportInstagramInsightsTotalValueJob;
use Inovector\Mixpost\SocialProviders\Meta\Jobs\ImportInstagramMediaJob;
use Inovector\Mixpost\SocialProviders\Meta\Jobs\ImportInstagramStoriesJob;
use Inovector\Mixpost\SocialProviders\Twitter\Jobs\ImportTwitterPostsJob;
use Inovector\Mixpost\Support\MediaFilesystem;

class Schedule
{
    public static function register($schedule, ?Builder $query = null, ?Closure $customCommands = null): void
    {
        $schedule->command('model:prune', [
            '--model' => [WebhookDelivery::class],
        ])->monthly();

        $schedule->command('mixpost:prune-temporary-directory')->hourly();

        if (MediaFilesystem::isCloudDisk(Util::config('disk'))) {
            $schedule->command('mixpost:cleanup-multipart-uploads')->daily();
        }

        $query = $query ?? Workspace::query()->select(['id', 'name']);

        $query
            ->each(function (Workspace $workspace) use ($schedule, $customCommands): void {
                if (! $workspace->valid()) {
                    return;
                }

                $schedule
                    ->job(new WorkspaceArtisanJob($workspace, RunScheduledPostsCommand::class))
                    ->name("$workspace->name - mixpost:run-scheduled-posts")
                    ->everyMinute();

                $schedule
                    ->job(new WorkspaceArtisanJob($workspace, CheckAndRefreshAccountTokenCommand::class))
                    ->name("$workspace->name - mixpost:check-and-refresh-account-token")
                    ->everyTenMinutes();

                $schedule
                    ->job(new WorkspaceArtisanJob($workspace, PruneTrashedPostsCommand::class))
                    ->name("$workspace->name - mixpost:prune-trashed-posts")
                    ->daily();

                $schedule
                    ->job(new WorkspaceArtisanJob($workspace, RunAccountProviderJobsCommand::class, ['priority' => 'high']))
                    ->name("$workspace->name - mixpost:run-account-jobs:high")
                    ->daily();

                $schedule
                    ->job(new WorkspaceArtisanJob($workspace, RunAccountProviderJobsCommand::class, ['priority' => 'medium']))
                    ->name("$workspace->name - mixpost:run-account-jobs:medium")
                    ->everyThreeHours();

                $schedule
                    ->job(new WorkspaceArtisanJob($workspace, RunAccountProviderJobsCommand::class, ['priority' => 'low']))
                    ->name("$workspace->name - mixpost:run-account-jobs:low")
                    ->everySixHours();

                $schedule
                    ->job(new WorkspaceArtisanJob($workspace, RunAccountProviderJobsCommand::class, ['priority' => 'daily']))
                    ->name("$workspace->name - mixpost:run-account-jobs:daily")
                    ->daily();

                self::scheduleTwitterPostAnalytics($schedule, $workspace, 'daily-0-7-days', 7)
                    ->dailyAt('00:05');
                self::scheduleTwitterPostAnalytics($schedule, $workspace, 'every-2-days-8-30-days', 30, 7)
                    ->dailyAt('00:20')
                    ->when(fn (): bool => self::isIntervalDay(2));
                self::scheduleTwitterPostAnalytics($schedule, $workspace, 'weekly-30-90-days', 90, 30)
                    ->weeklyOn(1, '00:40');
                self::scheduleTwitterPostAnalytics($schedule, $workspace, 'every-30-days-older-than-90-days', null, 90)
                    ->dailyAt('01:00')
                    ->when(fn (): bool => self::isIntervalDay(30));
                self::scheduleLowCostPostAnalytics($schedule, $workspace);

                if ($customCommands) {
                    $customCommands($workspace);
                }
            });
    }

    private static function scheduleTwitterPostAnalytics(
        $schedule,
        Workspace $workspace,
        string $cadence,
        ?int $startDaysAgo = null,
        ?int $endDaysAgo = null
    )
    {
        return $schedule
            ->call(function () use ($workspace, $startDaysAgo, $endDaysAgo): void {
                $workspace->execute(function () use ($startDaysAgo, $endDaysAgo): void {
                    $now = now('UTC');
                    $options = array_filter([
                        'timeline_start_time' => $startDaysAgo === null
                            ? null
                            : $now->copy()->subDays($startDaysAgo)->toIso8601ZuluString(),
                        'timeline_end_time' => $endDaysAgo === null
                            ? null
                            : $now->copy()->subDays($endDaysAgo)->toIso8601ZuluString(),
                    ]);

                    Account::query()
                        ->where('provider', 'twitter')
                        ->get()
                        ->filter(fn (Account $account): bool => $account->isAuthorized() && $account->isServiceActive())
                        ->each(fn (Account $account): mixed => ImportTwitterPostsJob::dispatch($account, $options));
                });
            })
            ->name("$workspace->name - mixpost:twitter-post-analytics-$cadence");
    }

    private static function isIntervalDay(int $intervalDays): bool
    {
        $utcDay = intdiv(now('UTC')->startOfDay()->timestamp, 86400);

        return $utcDay % $intervalDays === 0;
    }

    private static function scheduleLowCostPostAnalytics($schedule, Workspace $workspace): void
    {
        $schedule
            ->call(function () use ($workspace): void {
                $workspace->execute(function (): void {
                    Account::query()
                        ->whereIn('provider', ['instagram', 'instagram_standalone'])
                        ->get()
                        ->filter(fn (Account $account): bool => $account->isAuthorized() && $account->isServiceActive())
                        ->each(function (Account $account): void {
                            ImportInstagramStoriesJob::dispatch($account);
                            ImportInstagramInsightsTotalValueJob::dispatch($account);
                            ImportInstagramMediaJob::dispatch($account);
                            ImportInstagramAudienceJob::dispatch($account);
                        });

                    Account::query()
                        ->where('provider', 'facebook_page')
                        ->get()
                        ->filter(fn (Account $account): bool => $account->isAuthorized() && $account->isServiceActive())
                        ->each(function (Account $account): void {
                            ImportFacebookPagePostsJob::withChain([
                                new ImportFacebookPostInsightsJob($account),
                            ])->dispatch($account);
                        });

                    Account::query()
                        ->where('provider', 'youtube')
                        ->get()
                        ->filter(fn (Account $account): bool => $account->isAuthorized() && $account->isServiceActive())
                        ->each(fn (Account $account): mixed => ImportYoutubeVideosJob::dispatch($account));
                });
            })
            ->name("$workspace->name - mixpost:low-cost-post-analytics-30min")
            ->everyThirtyMinutes();
    }
}
