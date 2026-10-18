<?php

namespace Inovector\Mixpost\Support;

use FFMpeg\FFProbe;
use FFMpeg\FFProbe\DataMapping\Stream;
use Inovector\Mixpost\Util;
use Throwable;

/**
 * What a video file says about itself: how long it runs and the size it is shown at.
 *
 * Asked once, when a video is stored, and kept with the media — so nothing that needs a video's
 * length later has to open the file again.
 */
class VideoProbe
{
    /**
     * @return array{duration_ms: int, width: ?int, height: ?int}|null
     */
    public static function stored(string $disk, string $filepath): ?array
    {
        if (! Util::isFFmpegInstalled()) {
            return null;
        }

        $source = VideoSource::resolve($disk, $filepath);

        try {
            return static::read($source->path);
        } finally {
            $source->release();
        }
    }

    /**
     * An unreadable file is not a reason to refuse it: a video with no known length is still a
     * video, and whatever needs the length treats it as unknown.
     *
     * @return array{duration_ms: int, width: ?int, height: ?int}|null
     */
    public static function read(string $path): ?array
    {
        try {
            $ffprobe = FFProbe::create([
                'ffmpeg.binaries' => Util::config('ffmpeg_path'),
                'ffprobe.binaries' => Util::config('ffprobe_path'),
            ]);

            $seconds = (float) $ffprobe->format($path)->get('duration');
            $stream = $ffprobe->streams($path)->videos()->first();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if ($seconds <= 0) {
            return null;
        }

        return array_merge(['duration_ms' => (int) round($seconds * 1000)], static::dimensions($stream));
    }

    /**
     * A phone records sideways and tags the video with how to turn it, so a quarter-turned video
     * is shown with its width and height the other way round from how it is stored.
     */
    protected static function dimensions(?Stream $stream): array
    {
        $width = (int) $stream?->get('width');
        $height = (int) $stream?->get('height');

        if (! $width || ! $height) {
            return ['width' => null, 'height' => null];
        }

        // Stored pixels can be rectangular. Match the display size used by players,
        // expanding one axis before applying rotation rather than treating SAR as 1:1.
        $pixelRatio = static::ratio($stream->get('sample_aspect_ratio'));

        if ($pixelRatio === null) {
            $displayRatio = static::ratio($stream->get('display_aspect_ratio'));
            $pixelRatio = $displayRatio !== null ? $displayRatio * $height / $width : 1.0;
        }

        if ($pixelRatio >= 1) {
            $width = max(1, (int) round($width * $pixelRatio));
        } else {
            $height = max(1, (int) round($height / $pixelRatio));
        }

        if (abs(static::rotation($stream)) % 180 === 90) {
            return ['width' => $height, 'height' => $width];
        }

        return ['width' => $width, 'height' => $height];
    }

    protected static function ratio(mixed $value): ?float
    {
        if (! is_string($value) || ! preg_match('/^(\d+):(\d+)$/D', $value, $parts)) {
            return null;
        }

        $numerator = (float) $parts[1];
        $denominator = (float) $parts[2];

        if ($numerator <= 0 || $denominator <= 0) {
            return null;
        }

        $ratio = $numerator / $denominator;

        return is_finite($ratio) && $ratio > 0 ? $ratio : null;
    }

    // Older FFmpeg reports the turn as a tag, newer as side data; either says the same thing.
    protected static function rotation(Stream $stream): int
    {
        $tags = $stream->get('tags', []);

        if (isset($tags['rotate'])) {
            return (int) $tags['rotate'];
        }

        foreach ($stream->get('side_data_list', []) as $sideData) {
            if (isset($sideData['rotation'])) {
                return (int) $sideData['rotation'];
            }
        }

        return 0;
    }
}
