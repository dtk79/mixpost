<?php

namespace Inovector\Mixpost\MediaConversions;

use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use Illuminate\Support\Facades\File;
use Inovector\Mixpost\Abstracts\Image;
use Inovector\Mixpost\Abstracts\MediaConversion;
use Inovector\Mixpost\Support\ImageResizer;
use Inovector\Mixpost\Support\MediaConversionData;
use Inovector\Mixpost\Support\TemporaryDirectory;
use Inovector\Mixpost\Support\VideoSource;
use Inovector\Mixpost\Util;

class MediaVideoThumbConversion extends MediaConversion
{
    protected float $atSecond = 0;

    protected ?float $durationSeconds = null;

    public function getEngineName(): string
    {
        return 'VideoThumb';
    }

    public function canPerform(): bool
    {
        return $this->isVideo();
    }

    public function getPath(): string
    {
        return $this->getFilePathWithSuffix('jpg');
    }

    public function atSecond(float $value = 0): static
    {
        $this->atSecond = $value;

        return $this;
    }

    // A length already read from the file spares the conversion a probe of its own.
    public function durationMs(?int $value): static
    {
        $this->durationSeconds = $value !== null ? $value / 1000 : null;

        return $this;
    }

    public function handle(): ?MediaConversionData
    {
        if (! Util::isFFmpegInstalled()) {
            return null;
        }

        $videoSource = VideoSource::resolve($this->getFromDisk(), $this->getFilepath());
        $videoPath = $videoSource->path;

        // Create temp directory for thumbnail output
        $tempDir = TemporaryDirectory::make();
        $thumbFilepath = $tempDir->path('thumb_'.bin2hex(random_bytes(8)).'.jpg');

        $ffmpeg = FFMpeg::create([
            'ffmpeg.binaries' => Util::config('ffmpeg_path'),
            'ffprobe.binaries' => Util::config('ffprobe_path'),
        ]);

        $video = $ffmpeg->open($videoPath);
        $duration = $this->durationSeconds ?? $ffmpeg->getFFProbe()->format($videoPath)->get('duration');

        // The last whole second that still has a frame: a clip exactly three seconds long has none
        // at 3, and seeking there fails before the first-frame fallback below can run.
        $lastSecond = max(0, ceil($duration) - 1);
        $seconds = ($duration > 0 && $this->atSecond > 0) ? min($this->atSecond, $lastSecond) : 0;

        $frame = $video->frame(TimeCode::fromSeconds($seconds));
        $frame->filters()->custom(static::thumbnailFilter());
        $frame->save($thumbFilepath);

        // Sometimes the frame is not saved, so we save it again with the first frame
        // This is a workaround for the issue
        if ($this->atSecond !== 0 && ! File::exists($thumbFilepath)) {
            $frame = $video->frame(TimeCode::fromSeconds(0));
            $frame->filters()->custom(static::thumbnailFilter());
            $frame->save($thumbFilepath);
        }

        // Resize the thumbnail and save it to the destination disk
        ImageResizer::make($thumbFilepath)
            ->disk($this->getToDisk())
            ->path($this->getPath())
            ->resize(Image::MEDIUM_WIDTH, Image::MEDIUM_HEIGHT);

        // Clean up
        $tempDir->delete();
        $videoSource->release();

        return MediaConversionData::conversion($this);
    }

    protected static function thumbnailFilter(): string
    {
        // JPEG pixels are square: bake the video's display ratio into the frame's
        // dimensions. FFmpeg applies rotation before this filter. Bound the output
        // here so an anamorphic source never needs a full-size expanded JPEG.
        $width = Image::MEDIUM_WIDTH;
        $height = Image::MEDIUM_HEIGHT;

        return "scale='min(min(iw,ih*dar),min($width,$height*dar))':"
            ."'min(min(iw/dar,ih),min($width/dar,$height))',setsar=1";
    }
}
