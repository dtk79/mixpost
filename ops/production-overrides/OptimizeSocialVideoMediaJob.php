<?php

namespace Inovector\Mixpost\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Inovector\Mixpost\Concerns\Job\OnMediaQueue;
use Inovector\Mixpost\MediaConversions\MediaSocialVideoConversion;
use Inovector\Mixpost\Models\Media;

class OptimizeSocialVideoMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use OnMediaQueue;

    public int $timeout = 1800;

    public int $tries = 45;

    public function __construct(public int|string $mediaId)
    {
        $this->onQueue($this->viaQueue());
    }

    public function handle(): void
    {
        $media = Media::withoutGlobalScopes()->find($this->mediaId);

        if (! $media || ! $media->isVideo() || $media->getConversion('social_video')) {
            return;
        }

        if ($media->isProcessing()) {
            $this->release(15);

            return;
        }

        $perform = MediaSocialVideoConversion::name('social_video')
            ->filepath($media->path)
            ->fromDisk($media->disk)
            ->perform();

        if (! $perform) {
            return;
        }

        $conversion = $perform->get();
        $conversion['size'] ??= Storage::disk($conversion['disk'])->size($conversion['path']);

        $conversions = Collection::make($media->conversions ?: [])
            ->reject(fn (array $conversion): bool => ($conversion['name'] ?? null) === 'social_video')
            ->push($conversion)
            ->values()
            ->toArray();

        $media->forceFill([
            'conversions' => $conversions,
            'size_total' => $media->size + Collection::make($conversions)->sum('size'),
        ])->save();
    }
}
