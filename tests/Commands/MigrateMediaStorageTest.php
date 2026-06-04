<?php

use Illuminate\Support\Facades\Storage;
use Inovector\Mixpost\Commands\MigrateMediaStorage;
use Inovector\Mixpost\Models\Media;

beforeEach(function () {
    config()->set('filesystems.disks.mixpost_archive', [
        'driver' => 'local',
        'root' => storage_path('app/public/mixpost-archive'),
        'url' => env('APP_URL').'/storage/mixpost-archive',
        'visibility' => 'public',
        'throw' => false,
    ]);

    Storage::disk('mixpost_archive')->delete(Storage::disk('mixpost_archive')->allFiles());
});

it('migrates media files to another disk', function () {
    Storage::disk('mixpost_test')->put('05-2026/image.jpg', 'original');
    Storage::disk('mixpost_test')->put('05-2026/image-thumb.jpg', 'thumb');

    $media = Media::factory()->create([
        'disk' => 'mixpost_test',
        'path' => '05-2026/image.jpg',
        'conversions' => [
            [
                'name' => 'thumb',
                'disk' => 'mixpost_test',
                'path' => '05-2026/image-thumb.jpg',
            ],
        ],
    ]);

    $this->artisan(MigrateMediaStorage::class, [
        '--from' => 'mixpost_test',
        '--to' => 'mixpost_archive',
    ])->assertExitCode(0);

    $media->refresh();

    Storage::disk('mixpost_archive')->assertExists('05-2026/image.jpg');
    Storage::disk('mixpost_archive')->assertExists('05-2026/image-thumb.jpg');
    Storage::disk('mixpost_test')->assertExists('05-2026/image.jpg');

    expect($media->disk)->toBe('mixpost_archive')
        ->and($media->getConversion('thumb')['disk'])->toBe('mixpost_archive');
});

it('can delete source files after migration', function () {
    Storage::disk('mixpost_test')->put('05-2026/video.mp4', 'video');

    Media::factory()->create([
        'disk' => 'mixpost_test',
        'path' => '05-2026/video.mp4',
        'conversions' => [],
    ]);

    $this->artisan(MigrateMediaStorage::class, [
        '--from' => 'mixpost_test',
        '--to' => 'mixpost_archive',
        '--delete-source' => true,
    ])->assertExitCode(0);

    Storage::disk('mixpost_archive')->assertExists('05-2026/video.mp4');
    Storage::disk('mixpost_test')->assertMissing('05-2026/video.mp4');
});

it('does not copy or update records during a dry run', function () {
    Storage::disk('mixpost_test')->put('05-2026/image.jpg', 'original');

    $media = Media::factory()->create([
        'disk' => 'mixpost_test',
        'path' => '05-2026/image.jpg',
        'conversions' => [],
    ]);

    $this->artisan(MigrateMediaStorage::class, [
        '--from' => 'mixpost_test',
        '--to' => 'mixpost_archive',
        '--dry-run' => true,
    ])->assertExitCode(0);

    $media->refresh();

    Storage::disk('mixpost_archive')->assertMissing('05-2026/image.jpg');

    expect($media->disk)->toBe('mixpost_test');
});
