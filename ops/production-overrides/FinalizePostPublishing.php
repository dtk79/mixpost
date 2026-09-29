<?php

namespace Inovector\Mixpost\Actions\Post;

use Closure;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;
use Inovector\Mixpost\Notifications\PostPublishingFailedNotification;
use Throwable;
use Inovector\Mixpost\Events\Post\PostPublishedAll;
use Inovector\Mixpost\Events\Post\PostPublishProgress;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Models\Post;

/**
 * Settles a post once every account has been through the publishing pipeline. Shared by the initial
 * publishing batch and by a per-account retry so both arrive at the same status.
 */
class FinalizePostPublishing
{
    /**
     * Batch callbacks run as plain queued closures, so no workspace is bound to the worker and every
     * workspace-scoped query would otherwise resolve against whichever workspace the previous job on
     * that worker happened to leave behind.
     */
    public static function callback(Post $post, int $workspaceId): Closure
    {
        return function (Batch $batch) use ($post, $workspaceId) {
            if (! $workspace = WorkspaceManager::findById($workspaceId)) {
                return;
            }

            WorkspaceManager::setCurrent($workspace);

            (new self)($post, $batch->hasFailures());
        };
    }

    public function __invoke(Post $post, bool $batchHadFailures = false): void
    {
        $post->withPublishingLock(function () use ($post, $batchHadFailures) {
            if ($post->endPublishingRun() > 0) {
                PostPublishProgress::dispatch($post);

                return;
            }

            // Read again under the lock: the copy the batch carried may predate a run that finished
            // meanwhile and moved the post on.
            $post = $post->fresh();

            if (! $post) {
                return;
            }

            $this->settle($post, $batchHadFailures);
        });
    }

    protected function settle(Post $post, bool $batchHadFailures = false): void
    {
        // A staggered post comes back here after every departure. Settling it on the first one
        // would publish the rest of its accounts never: the runner only looks at posts that are
        // still scheduled.
        if ($post->hasPendingAccounts()) {
            $post->setSchedulePending();

            PostPublishProgress::dispatch($post);

            return;
        }

        if ($post->hasErrors() || $batchHadFailures) {
            $post->setFailed();

            try {
                Notification::route('mail', 'socials@ducatix.com')->notify(
                    (new PostPublishingFailedNotification($post, $batchHadFailures))->delay(now()->addSeconds(10))
                );
                Log::info('mixpost.publish_failure_notification_queued', ['post_id' => $post->id]);
            } catch (Throwable $exception) {
                report($exception);
            }

            PostPublishProgress::dispatch($post);

            return;
        }

        if ($post->isScheduleProcessing()) {
            $post->setPublished();

            PostPublishedAll::dispatch($post);
        }

        PostPublishProgress::dispatch($post);
    }
}
