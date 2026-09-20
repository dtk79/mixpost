<?php

namespace Inovector\Mixpost\Actions\Post;

use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Inovector\Mixpost\Jobs\AccountPublishPostJob;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Post;
use Inovector\Mixpost\Notifications\PostPublishingFailedNotification;
use Inovector\Mixpost\Util;
use Throwable;

class PublishPost
{
    private const FAILURE_NOTIFICATION_EMAIL = 'socials@ducatix.com';

    public function __invoke(Post $post): void
    {
        if ($post->isScheduleProcessing()) {
            return;
        }

        $post->setScheduleProcessing();

        $jobs = $post->accounts->map(function (Account $account) use ($post) {
            return new AccountPublishPostJob($account, $post);
        });

        Bus::batch($jobs)
            ->allowFailures()
            ->finally(function (Batch $batch) use ($post) {
                if ($post->hasErrors() || $batch->hasFailures()) {
                    $post->setFailed();

                    try {
                        Notification::route('mail', self::FAILURE_NOTIFICATION_EMAIL)
                            ->notify(
                                (new PostPublishingFailedNotification($post, $batch->hasFailures()))
                                    ->delay(now()->addSeconds(10))
                            );
                    } catch (Throwable $exception) {
                        report($exception);
                    }

                    return;
                }

                if ($post->isScheduleProcessing()) {
                    $post->setPublished();
                }
            })
            ->onQueue(Util::config('queue.publish_post'))
            ->dispatch();
    }
}
