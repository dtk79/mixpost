<?php

namespace Inovector\Mixpost\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Inovector\Mixpost\Concerns\Job\OnMediaQueue;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Models\Workspace;
use Inovector\Mixpost\Support\LocalMediaStaging;

class DeliverStagedMediaJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, OnMediaQueue;
    public int $timeout = 1800;
    public int $uniqueFor = 1900;
    public int $tries = 1;
    public function __construct(public int|string $mediaId) { $this->onConnection('peachy-media')->onQueue($this->viaQueue()); }
    public function uniqueId(): string { return (string) $this->mediaId; }
    public function handle(): void
    {
        $media = Media::withoutGlobalScopes()->find($this->mediaId);
        if (! $media || ! LocalMediaStaging::needsWork($media)) { return; }
        $workspace = Workspace::find($media->workspace_id);
        if (! $workspace) { return; }
        $workspace->execute(fn () => LocalMediaStaging::deliver($media));
    }
}
