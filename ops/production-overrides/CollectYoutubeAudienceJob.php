<?php

namespace Inovector\Mixpost\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Inovector\Mixpost\Concerns\Job\OnAnalyticsQueue;
use Inovector\Mixpost\Contracts\QueueWorkspaceAware;
use Inovector\Mixpost\Support\YoutubeAudienceCollector;
use Throwable;
use RuntimeException;

final class CollectYoutubeAudienceJob implements QueueWorkspaceAware, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, OnAnalyticsQueue;

    public int $timeout = 120;
    public int $tries = 10;
    public int $backoff = 60;

    public function __construct(public int $workspaceId, public int $accountId, public string $start, public string $end)
    {
        $this->onQueue($this->viaQueue());
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping("youtube-audience:$this->workspaceId:$this->accountId"))->shared()->releaseAfter(30)->expireAfter(180)];
    }

    public function handle(YoutubeAudienceCollector $collector): void
    {
        try {
            $collector->collect($this->workspaceId, $this->accountId, $this->start, $this->end);
        } catch (Throwable) {
            throw new RuntimeException('YouTube audience snapshot collection failed. Check scoped configuration, table migration and account availability.');
        }
    }
}
