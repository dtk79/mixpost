<?php

namespace Inovector\Mixpost\Actions\Post;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Jobs\AccountPublishPostJob;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Post;
use Inovector\Mixpost\Util;

class PublishPost
{
    /**
     * Publishes every account of the post, or only the ones handed in when the accounts leave at
     * times of their own — a staggered post comes through here once per departure, and the
     * accounts that already went out must not be reset and sent again.
     */
    public function __invoke(Post $post, ?Collection $accounts = null): void
    {
        if ($accounts === null) {
            // Everyone starts over, which would pull the accounts of a run still sending from under it.
            if ($post->isScheduleProcessing()) {
                return;
            }

            $post->resetAccountsPublishState();

            $accounts = $post->accounts;
        }

        $accounts = $post->startPublishingRun($accounts);

        if ($accounts->isEmpty()) {
            return;
        }

        $workspaceId = WorkspaceManager::current()->id;

        $jobs = $accounts->map(function (Account $account) use ($post) {
            return new AccountPublishPostJob($account, $post);
        });

        Bus::batch($jobs)
            ->allowFailures()
            ->finally(FinalizePostPublishing::callback($post, $workspaceId))
            ->onQueue(Util::config('queue.publish_post'))
            ->dispatch();
    }
}
