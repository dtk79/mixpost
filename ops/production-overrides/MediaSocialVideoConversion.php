<?php

namespace Inovector\Mixpost\MediaConversions;

use Inovector\Mixpost\Abstracts\MediaConversion;
use Inovector\Mixpost\Support\MediaConversionData;
use Inovector\Mixpost\Support\TemporaryDirectory;
use Inovector\Mixpost\Support\TemporaryFile;
use Inovector\Mixpost\Util;
use RuntimeException;

class MediaSocialVideoConversion extends MediaConversion
{
    public function getEngineName(): string
    {
        return 'SocialVideo';
    }

    public function canPerform(): bool
    {
        return $this->isVideo() && Util::isFFmpegInstalled();
    }

    public function getPath(): string
    {
        return $this->getFilePathWithSuffix('mp4');
    }

    public function handle(): ?MediaConversionData
    {
        $source = TemporaryFile::make()->fromDisk($this->getFromDisk(), $this->getFilepath());
        $outputDirectory = TemporaryDirectory::make();
        $output = $outputDirectory->path('social_video_'.bin2hex(random_bytes(8)).'.mp4');

        try {
            $command = implode(' ', array_map('escapeshellarg', [
                Util::config('ffmpeg_path'),
                '-y',
                '-i',
                $source->filepath(),
                '-t',
                '90',
                '-vf',
                "scale='min(1080,iw)':-2:force_original_aspect_ratio=decrease,fps=30,format=yuv420p",
                '-c:v',
                'libx264',
                '-preset',
                'veryfast',
                '-profile:v',
                'high',
                '-level',
                '4.1',
                '-b:v',
                '8000k',
                '-maxrate',
                '8000k',
                '-bufsize',
                '16000k',
                '-c:a',
                'aac',
                '-b:a',
                '128k',
                '-movflags',
                '+faststart',
                $output,
            ]));

            exec($command.' 2>&1', $outputLines, $exitCode);

            if ($exitCode !== 0) {
                throw new RuntimeException(implode("\n", $outputLines));
            }

            $stream = fopen($output, 'r');
            $this->filesystem($this->getToDisk())->put($this->getPath(), $stream, 'public');
            fclose($stream);

            return MediaConversionData::conversion($this);
        } catch (\Throwable $e) {
            throw new RuntimeException('Social video optimization failed: '.$e->getMessage(), previous: $e);
        } finally {
            $source->directory()->delete();
            $outputDirectory->delete();
        }
    }
}
