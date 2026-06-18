<?php

namespace Inovector\Mixpost\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Inovector\Mixpost\Models\Media;

class MigrateMediaStorage extends Command
{
    public $signature = 'mixpost:migrate-media-storage
        {--from= : Source disk to migrate from}
        {--to= : Target disk to migrate to}
        {--before= : Only migrate media created before this date, for example 2026-01-01}
        {--delete-source : Delete source files after the media record is updated}
        {--dry-run : Show what would be migrated without copying files or updating records}';

    public $description = 'Migrate Mixpost media files between configured filesystem disks';

    public function handle(): int
    {
        $fromDisk = $this->option('from') ?: 'public';
        $toDisk = $this->option('to') ?: config('mixpost.disk');

        if ($fromDisk === $toDisk) {
            $this->error('The source and target disks must be different.');

            return self::FAILURE;
        }

        $query = Media::query()
            ->where('disk', $fromDisk)
            ->where('disk', '!=', 'external_media');

        if ($before = $this->option('before')) {
            $query->where('created_at', '<', $before);
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info('No media records matched the migration filters.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Dry run: $total media records would be migrated from [$fromDisk] to [$toDisk].");

            return self::SUCCESS;
        }

        $migrated = 0;
        $failed = 0;

        $this->output->progressStart($total);

        $query->orderBy('id')->chunkById(100, function ($mediaRecords) use ($fromDisk, $toDisk, &$migrated, &$failed) {
            foreach ($mediaRecords as $media) {
                try {
                    $sourceFiles = $this->sourceFiles($media);

                    foreach ($sourceFiles as $path) {
                        $this->copyFile($fromDisk, $toDisk, $path);
                    }

                    $media->forceFill([
                        'disk' => $toDisk,
                        'conversions' => $this->migratedConversions($media->conversions, $toDisk),
                    ])->save();

                    if ($this->option('delete-source')) {
                        Storage::disk($fromDisk)->delete($sourceFiles);
                    }

                    $migrated++;
                } catch (\Throwable $exception) {
                    $failed++;
                    $this->newLine();
                    $this->error("Failed to migrate media [{$media->id}]: {$exception->getMessage()}");
                }

                $this->output->progressAdvance();
            }
        });

        $this->output->progressFinish();
        $this->info("Migrated $migrated media records from [$fromDisk] to [$toDisk].");

        if ($failed > 0) {
            $this->warn("$failed media records failed.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    protected function sourceFiles(Media $media): array
    {
        return collect([$media->path])
            ->merge(collect($media->conversions)->pluck('path'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    protected function copyFile(string $fromDisk, string $toDisk, string $path): void
    {
        $source = Storage::disk($fromDisk);
        $target = Storage::disk($toDisk);

        if (! $source->fileExists($path)) {
            throw new \RuntimeException("Source file [$path] does not exist on disk [$fromDisk].");
        }

        $stream = $source->readStream($path);

        if ($stream === false) {
            throw new \RuntimeException("Unable to read source file [$path] from disk [$fromDisk].");
        }

        try {
            if (! $target->put($path, $stream, 'public')) {
                throw new \RuntimeException("Unable to write target file [$path] to disk [$toDisk].");
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    protected function migratedConversions(?array $conversions, string $toDisk): array
    {
        return collect($conversions)
            ->map(function (array $conversion) use ($toDisk) {
                $conversion['disk'] = $toDisk;

                return $conversion;
            })
            ->values()
            ->all();
    }
}
