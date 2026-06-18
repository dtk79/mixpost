<?php

namespace Inovector\Mixpost\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Models\Workspace;

class MigrateStorage extends Command
{
    protected $signature = 'mixpost:migrate-storage
                            {sourceDisk : Source disk name (e.g., public, s3)}
                            {targetDisk : Target disk name (e.g., s3, public)}
                            {--dry-run : Show what would be migrated without actually migrating}';

    protected $description = 'Migrate storage files from one disk to another';

    protected array $sourceDiskConfig = [];

    protected array $targetDiskConfig = [];

    public function handle(): int
    {
        $sourceDisk = $this->argument('sourceDisk');
        $targetDisk = $this->argument('targetDisk');
        $dryRun = $this->option('dry-run');

        if ($sourceDisk === $targetDisk) {
            $this->error('Source and target disks must be different.');

            return self::FAILURE;
        }

        // Handle S3 credentials for source disk
        $this->sourceDiskConfig = $this->configureDisk($sourceDisk, 'source');
        if (! $this->sourceDiskConfig) {
            return self::FAILURE;
        }

        // Handle S3 credentials for target disk
        $this->targetDiskConfig = $this->configureDisk($targetDisk, 'target');
        if (! $this->targetDiskConfig) {
            return self::FAILURE;
        }

        $workspaces = Workspace::all();

        if ($workspaces->isEmpty()) {
            $this->warn('No workspaces found to migrate.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s storage for %d workspace(s) from "%s" to "%s"',
            $dryRun ? 'Would migrate' : 'Migrating',
            $workspaces->count(),
            $sourceDisk,
            $targetDisk
        ));

        $migratedFiles = 0;
        $errors = [];

        foreach ($workspaces as $workspace) {
            $this->newLine();
            $this->info(sprintf(
                'Processing workspace: %s (ID: %d, UUID: %s)',
                $workspace->name,
                $workspace->id,
                $workspace->uuid
            ));

            // Migrate main workspace directory
            $mainPath = $workspace->uuid;
            $migratedFiles += $this->migrateDirectory($sourceDisk, $targetDisk, $mainPath, $dryRun, $errors);

            // Migrate imported directory
            $importedPath = 'imported/'.$workspace->uuid;
            $migratedFiles += $this->migrateDirectory($sourceDisk, $targetDisk, $importedPath, $dryRun, $errors);
        }

        // Update Media records
        $this->newLine();
        if (! $dryRun) {
            $this->updateAllMediaRecords($sourceDisk, $targetDisk, $errors);
        } else {
            $count = Media::withoutWorkspace()->where('disk', $sourceDisk)->count();
            $this->line("  [DRY-RUN] Would update {$count} Media records from disk '{$sourceDisk}' to '{$targetDisk}'");
        }

        if (! empty($errors)) {
            $this->newLine();
            $this->warn('Errors encountered:');
            foreach ($errors as $error) {
                $this->warn("  - {$error}");
            }
        }

        $this->newLine();
        $action = $dryRun ? 'Would migrate' : 'Migrated';
        $this->info("{$action} {$migratedFiles} files total.");

        if (! $dryRun && empty($errors)) {
            $this->info('Migration completed successfully for all workspaces.');
            $this->warn("You may now delete files from the source disk '{$sourceDisk}' after verification.");
        }

        return empty($errors) ? self::SUCCESS : self::FAILURE;
    }

    protected function configureDisk(string $diskName, string $label): ?array
    {
        $diskConfig = config("filesystems.disks.{$diskName}", []);

        // Check if this is an S3-compatible disk (either configured or user wants to use S3)
        if ($this->isS3Disk($diskConfig) || $this->isLikelyS3Name($diskName)) {
            $this->info("S3 disk '{$diskName}' detected for {$label}.");

            // Prompt for S3 credentials if missing
            if (empty($diskConfig['key']) || empty($diskConfig['secret'])) {
                $this->warn("S3 credentials are required for disk '{$diskName}'.");

                // Set S3 driver if not set
                if (empty($diskConfig['driver'])) {
                    $diskConfig['driver'] = 's3';
                }

                $diskConfig['key'] = $this->ask('S3 Access Key ID');
                $diskConfig['secret'] = $this->secret('S3 Secret Access Key');
                $diskConfig['region'] = $this->ask('S3 Region', $diskConfig['region'] ?? 'us-east-1');
                $diskConfig['bucket'] = $this->ask('S3 Bucket');

                $useEndpoint = $this->confirm('Do you want to use a custom S3 endpoint?', false);
                if ($useEndpoint) {
                    $diskConfig['endpoint'] = $this->ask('S3 Endpoint URL');
                    $diskConfig['use_path_style_endpoint'] = $this->confirm('Use path-style endpoint?', false);
                }

                // Test the connection
                if (! $this->testS3Connection($diskName, $diskConfig)) {
                    if (! $this->confirm('Connection test failed. Continue anyway?', false)) {
                        return null;
                    }
                }
            }
        }

        return $diskConfig;
    }

    protected function isLikelyS3Name(string $diskName): bool
    {
        $s3Names = ['s3', 'minio', 'spaces', 'digitalocean', 'do', 'wasabi', 'backblaze', 'b2', 'storj'];
        $lowerName = strtolower($diskName);

        foreach ($s3Names as $s3Name) {
            if (str_contains($lowerName, $s3Name)) {
                return true;
            }
        }

        return false;
    }

    protected function isS3Disk(array $config): bool
    {
        // Check for S3 driver or S3-compatible drivers
        $driver = $config['driver'] ?? '';

        return in_array($driver, ['s3', 's3_compatible', 'minio', 'digitalocean', 'spaces'], true) ||
            (isset($config['region']) && isset($config['bucket']));
    }

    protected function testS3Connection(string $diskName, array $config): bool
    {
        try {
            // Create a temporary disk configuration
            $tempDiskName = "_temp_test_{$diskName}";
            Config::set("filesystems.disks.{$tempDiskName}", $config);

            $disk = Storage::disk($tempDiskName);

            // Avoid S3 prefix listing; a HEAD on a harmless key still validates the client can talk to S3.
            $disk->fileExists('__mixpost_connection_test__');

            $this->info("  ✓ S3 connection test successful for '{$diskName}'.");

            return true;
        } catch (Exception $e) {
            $this->warn("  ✗ S3 connection test failed: {$e->getMessage()}");

            return false;
        }
    }

    protected function getConfiguredDisk(string $diskName, array $config): mixed
    {
        // If config is empty, it's likely a local disk - set up default local config
        if (empty($config)) {
            $config = [
                'driver' => 'local',
                'root' => storage_path("app/{$diskName}"),
            ];
        }

        // Create a temporary disk with the provided configuration
        $tempDiskName = "_temp_migrate_{$diskName}_".uniqid();
        Config::set("filesystems.disks.{$tempDiskName}", $config);

        return Storage::disk($tempDiskName);
    }

    protected function migrateDirectory(
        string $sourceDisk,
        string $targetDisk,
        string $path,
        bool $dryRun,
        array &$errors
    ): int {
        $sourceStorage = $this->getConfiguredDisk($sourceDisk, $this->sourceDiskConfig);
        $targetStorage = $this->getConfiguredDisk($targetDisk, $this->targetDiskConfig);

        $files = $this->mediaFilesForPrefix($sourceDisk, $path);

        if (empty($files)) {
            $this->line("  No media files found under '{$path}', skipping.");

            return 0;
        }

        $migratedCount = 0;

        foreach ($files as $file) {
            if ($dryRun) {
                $this->line("  [DRY-RUN] Would copy: {$file}");
                $migratedCount++;

                continue;
            }

            try {
                // Check if file already exists on target
                if ($targetStorage->fileExists($file)) {
                    $this->warn("  File already exists on target disk, skipping: {$file}");

                    continue;
                }

                if (! $sourceStorage->fileExists($file)) {
                    $this->warn("  Source file does not exist, skipping: {$file}");

                    continue;
                }

                // Copy file between disks using streaming
                $stream = $sourceStorage->readStream($file);

                try {
                    $targetStorage->writeStream($file, $stream, ['visibility' => 'public']);
                    $this->line("  Copied: {$file}");
                    $migratedCount++;
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
            } catch (Exception $e) {
                $errors[] = "Error copying {$file}: {$e->getMessage()}";
            }
        }

        return $migratedCount;
    }

    protected function mediaFilesForPrefix(string $sourceDisk, string $path): array
    {
        $prefix = trim($path, '/').'/';

        // ponytail: media table is the manifest; use S3 listing only if non-media files must migrate.
        return Media::withoutWorkspace()
            ->where(function ($query) use ($sourceDisk) {
                $query->where('disk', $sourceDisk)
                    ->orWhereNotNull('conversions');
            })
            ->get()
            ->flatMap(function (Media $media) use ($sourceDisk) {
                $files = [];

                if ($media->disk === $sourceDisk && $media->path) {
                    $files[] = $media->path;
                }

                foreach (($media->conversions ?: []) as $conversion) {
                    if (($conversion['disk'] ?? null) === $sourceDisk && ! empty($conversion['path'])) {
                        $files[] = $conversion['path'];
                    }
                }

                return $files;
            })
            ->filter(fn (string $file): bool => str_starts_with($file, $prefix))
            ->unique()
            ->values()
            ->all();
    }

    protected function updateAllMediaRecords(
        string $sourceDisk,
        string $targetDisk,
        array &$errors
    ): void {
        $this->info('Updating Media records...');

        try {
            // Update main media records
            $mediaUpdated = Media::withoutWorkspace()
                ->where('disk', $sourceDisk)
                ->update(['disk' => $targetDisk]);

            $this->line("  Updated {$mediaUpdated} Media records.");

            // Update conversions that reference the source disk
            $mediaWithConversions = Media::withoutWorkspace()
                ->whereNotNull('conversions')
                ->get();

            $conversionUpdates = 0;
            foreach ($mediaWithConversions as $media) {
                $conversions = $media->conversions;
                $hasChanges = false;

                if (is_array($conversions)) {
                    foreach ($conversions as $key => $conversion) {
                        if (is_array($conversion) && ($conversion['disk'] ?? null) === $sourceDisk) {
                            $conversions[$key]['disk'] = $targetDisk;
                            $hasChanges = true;
                        }
                    }
                }

                if ($hasChanges) {
                    $media->conversions = $conversions;
                    $media->save();
                    $conversionUpdates++;
                }
            }

            if ($conversionUpdates > 0) {
                $this->line("  Updated conversions in {$conversionUpdates} Media records.");
            }
        } catch (Exception $e) {
            $errors[] = "Error updating Media records: {$e->getMessage()}";
        }
    }
}
