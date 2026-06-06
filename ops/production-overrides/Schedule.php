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

                self::scheduleTwitterPostAnalytics($schedule, $workspace, true, 'daily');
                self::scheduleTwitterPostAnalytics($schedule, $workspace, false, 'monthly');

                if ($customCommands) {
                    $customCommands($workspace);
                }
            });
    }

    private static function scheduleTwitterPostAnalytics($schedule, Workspace $workspace, bool $active, string $cadence): void
    {
        $event = $schedule
            ->call(function () use ($workspace, $active): void {
                $workspace->execute(function () use ($active): void {
                    Account::query()
                        ->where('provider', 'twitter')
                        ->get()
                        ->filter(fn (Account $account): bool => $account->isAuthorized() && $account->isServiceActive())
                        ->filter(fn (Account $account): bool => self::isActiveTwitterAccount($account) === $active)
                        ->each(fn (Account $account): mixed => ImportTwitterPostsJob::dispatch($account));
                });
            })
            ->name("$workspace->name - mixpost:twitter-post-analytics-$cadence");

        $cadence === 'daily'
            ? $event->daily()
            : $event->monthly();
    }

    private static function isActiveTwitterAccount(Account $account): bool
    {
        $recentPostCount = $account->posts()
            ->whereNotNull('published_at')
            ->where('published_at', '>=', now('UTC')->subDays(30))
            ->count();

        if ($recentPostCount >= 3) {
            return true;
        }

        return $account->posts()
            ->whereNotNull('published_at')
            ->where('published_at', '>=', now('UTC')->subDays(14))
            ->exists();
    }
}
