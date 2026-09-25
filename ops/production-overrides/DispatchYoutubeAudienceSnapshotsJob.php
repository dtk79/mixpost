<?php

namespace Inovector\Mixpost\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Schema;
use Inovector\Mixpost\Concerns\Job\OnAnalyticsQueue;
use Inovector\Mixpost\Contracts\QueueWorkspaceAware;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Support\YoutubeAudienceCollector;
use Inovector\Mixpost\Support\YoutubeAudienceSnapshot;

/** Participates in the existing six-hour low-priority provider cycle; no global scheduler override. */
final class DispatchYoutubeAudienceSnapshotsJob implements QueueWorkspaceAware, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, OnAnalyticsQueue;

    public int $timeout = 30;
    public int $tries = 1;
    public int $workspaceId;
    public int $accountId;

    public function __construct(Account $account)
    {
        // IDs are re-resolved within the active workspace when the job runs, avoiding stale credential serialization.
        $this->workspaceId = (int) $account->workspace_id;
        $this->accountId = (int) $account->id;
        $this->onQueue($this->viaQueue());
    }

    public function handle(): void
    {
        if (! in_array($this->workspaceId, YoutubeAudienceCollector::workspaceIds(), true)
            || (int) WorkspaceManager::current()?->id !== $this->workspaceId
            || ! Schema::hasTable(YoutubeAudienceSnapshot::TABLE)) {
            return;
        }
        $account = Account::query()->where('id', $this->accountId)->where('workspace_id', $this->workspaceId)->where('provider', 'youtube')->first();
        if (! $account || ! $account->isAuthorized() || ! $account->isServiceActive()) {
            return;
        }
        foreach (YoutubeAudienceSnapshot::windows() as $index => $range) {
            CollectYoutubeAudienceJob::dispatch($this->workspaceId, $this->accountId, $range['start'], $range['end'])->delay($index * 30);
        }
    }
}
