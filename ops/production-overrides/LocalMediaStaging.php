<?php

namespace Inovector\Mixpost\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inovector\Mixpost\Events\Media\MediaUpdated;
use Inovector\Mixpost\Jobs\ConvertMediaVideoJob;
use Inovector\Mixpost\Jobs\DeliverStagedMediaJob;
use Inovector\Mixpost\Jobs\OptimizeSocialVideoMediaJob;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Util;
use Throwable;

class LocalMediaStaging
{
    public const DISK = 'public';
    public const STATE_KEY = 'peachy_storage';

    public static function enabled(): bool { return MediaFilesystem::isCloudDisk(Util::config('disk')); }
    public static function ready(Media $media): bool
    {
        return ! isset($media->data[self::STATE_KEY]) || ($media->data[self::STATE_KEY]['state'] ?? '') === 'ready';
    }
    public static function needsWork(Media $media): bool
    {
        return ! self::ready($media) || ! empty($media->data[self::STATE_KEY]['local_files'])
            || ! empty($media->data[self::STATE_KEY]['replaced_files']);
    }

    public static function ensureCapacity(int $bytes): void
    {
        $free = disk_free_space(storage_path('app'));
        $pendingBytes = Media::withoutGlobalScopes()->whereNotNull('data->peachy_storage')
            ->where('data->peachy_storage->state', '!=', 'ready')->sum('size_total');
        if (! self::capacityAllows($bytes, $free, (int) $pendingBytes)) {
            throw ValidationException::withMessages(['file' => ['Upload storage is temporarily full. Please try again after pending files finish preparing.']]);
        }
    }
    public static function capacityAllows(int $bytes, int|false $free, int $pendingBytes): bool
    {
        return $bytes >= 0 && $free !== false && $free >= 5 * 1024 ** 3 + 3 * $bytes
            && $pendingBytes + $bytes <= 20 * 1024 ** 3;
    }

    public static function store(MediaUploader $uploader, ?string $uploadUuid = null): Media
    {
        // upload() stores locally but does not enqueue the vendor's conversion job.
        $attributes = Arr::only($uploader->disk(self::DISK)->upload(), [
            'name', 'mime_type', 'folder_id', 'size', 'size_total', 'disk', 'path', 'conversions', 'processing', 'data',
        ]);
        $existing = isset($attributes['data']['adobe_express_doc_id'])
            ? Media::whereJsonContains('data->adobe_express_doc_id', $attributes['data']['adobe_express_doc_id'])->first() : null;
        $lock = null;
        $stored = false;
        try {
            if ($existing) {
                $lockPath = storage_path('app/peachy-upload-state/locks/'.$existing->id.'.lock');
                $lock = fopen($lockPath, 'c');
                if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB) || self::needsWork($existing->refresh())) {
                    throw ValidationException::withMessages(['file' => ['This media is still preparing. Please retry the edit after it finishes.']]);
                }
            }
            $oldFiles = $existing ? array_merge([['disk' => $existing->disk, 'path' => $existing->path]], $existing->conversions ?? []) : [];
            $attributes['data'][self::STATE_KEY] = [
                'state' => 'pending', 'target_disk' => Util::config('disk'), 'upload_uuid' => $uploadUuid,
                'attempts' => 0, 'next_attempt_at' => 0, 'replaced_files' => $oldFiles,
            ];
            foreach (array_merge([['path' => $attributes['path']]], $attributes['conversions']) as $file) {
                $handle = fopen(Storage::disk(self::DISK)->path($file['path']), 'r+');
                try { if (! $handle || ! fsync($handle)) { throw new \RuntimeException('Cannot sync accepted file'); } }
                finally { if ($handle) { fclose($handle); } }
            }
            $attributes['processing'] = true;
            if ($existing) { $existing->forceFill($attributes)->save(); $media = $existing->refresh(); }
            else { $media = Media::create($attributes); }
            $stored = true;
            self::enqueue($media);
            return $media;
        } finally {
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
            if (! $stored) {
                foreach (array_merge([['path' => $attributes['path']]], $attributes['conversions']) as $file) {
                    Storage::disk(self::DISK)->delete($file['path']);
                }
            }
        }
    }

    public static function enqueue(Media $media): void
    {
        try { DeliverStagedMediaJob::dispatch($media->id); }
        catch (Throwable $error) {
            // PendingDispatch may have obtained its uniqueness lock before Redis
            // dispatch failed. Let the outbox scanner retry instead of waiting 1900s.
            try {
                (new \Illuminate\Bus\UniqueLock(app(\Illuminate\Contracts\Cache\Repository::class)))
                    ->release(new DeliverStagedMediaJob($media->id));
            } catch (Throwable $cleanupError) { /* Cache outage: the finite lease still expires. */ }
            Log::warning('mixpost.staged_media.queue_unavailable', ['media_id' => $media->id, 'exception_class' => get_class($error)]);
        }
    }

    public static function dispatchPending(): void
    {
        Media::withoutGlobalScopes()->whereNotNull('data->peachy_storage')
            ->where(function ($query) { $query->where('data->peachy_storage->state', '!=', 'ready')
                ->orWhereJsonLength('data->peachy_storage->local_files', '>', 0)
                ->orWhereJsonLength('data->peachy_storage->replaced_files', '>', 0);  })
            ->where('data->peachy_storage->next_attempt_at', '<=', time())
            ->orderBy('id')->limit(50)->get()->each(fn (Media $media) => self::enqueue($media));
        LocalChunkedUpload::pruneExpired();
    }

    public static function deliver(Media $media): void
    {
        $lockPath = storage_path('app/peachy-upload-state/locks/'.$media->id.'.lock');
        if (! is_dir(dirname($lockPath))) { mkdir(dirname($lockPath), 0755, true); }
        $lock = fopen($lockPath, 'c');
        if ($lock === false) { throw new \RuntimeException('Cannot lock staged media'); }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) { return; }
            $media->refresh();
            if (self::ready($media)) { self::cleanupLocalFiles($media); return; }
            $state = $media->data[self::STATE_KEY];
            if (($state['next_attempt_at'] ?? 0) > time()) { return; }
            self::updateState($media, ['next_attempt_at' => time() + 1900]);
            // Prepare on the local disk; original and derivatives stay recoverable together.
            if ($media->isVideo()) {
                if ($media->mime_type !== 'video/mp4' && empty($state['retained_local_files'])) {
                    // The vendor converter deletes its input after replacing it with MP4.
                    // Keep accepted source bytes until cloud promotion succeeds.
                    $retainedPath = $media->path.'.peachy-source';
                    if (! Storage::disk(self::DISK)->copy($media->path, $retainedPath)) {
                        throw new \RuntimeException('Cannot retain accepted video source');
                    }
                    $handle = fopen(Storage::disk(self::DISK)->path($retainedPath), 'r+');
                    try { if (! $handle || ! fsync($handle)) { throw new \RuntimeException('Cannot sync retained video source'); } }
                    finally { if ($handle) { fclose($handle); } }
                    self::updateState($media, ['retained_local_files' => [['disk' => self::DISK, 'path' => $retainedPath]]]);
                }
                if ($media->isProcessing()) { (new ConvertMediaVideoJob($media))->handle(); }
                (new OptimizeSocialVideoMediaJob($media->id))->handle();
                $media->refresh();
            }
            $targetDisk = $state['target_disk'];
            $remote = Storage::disk($targetDisk);
            $transfer = new StagedFileTransfer($remote->getClient(), $remote->getConfig()['bucket']);
            $files = [['disk' => $media->disk, 'path' => $media->path, 'mime_type' => $media->mime_type]];
            foreach ($media->conversions ?? [] as $conversion) { $files[] = $conversion; }
            self::updateState($media, ['local_files' => array_merge($files, $media->data[self::STATE_KEY]['retained_local_files'] ?? [])]);
            foreach ($files as $file) {
                if ($file['disk'] !== self::DISK) { throw new \RuntimeException('Unexpected staged file disk'); }
                $transfer->transfer(Storage::disk(self::DISK)->path($file['path']), $file['path'], $file['mime_type'] ?? 'application/octet-stream');
            }
            DB::transaction(function () use ($media, $targetDisk): void {
                $current = Media::withoutGlobalScopes()->lockForUpdate()->findOrFail($media->id);
                if ($current->disk !== self::DISK || $current->path !== $media->path) {
                    throw new \RuntimeException('Staged media changed during transfer');
                }
                $conversions = array_map(fn ($item) => array_replace($item, ['disk' => $targetDisk]), $current->conversions ?? []);
                $data = $current->data ?? [];
                $data[self::STATE_KEY] = array_replace($data[self::STATE_KEY], ['state' => 'ready', 'next_attempt_at' => 0, 'ready_at' => gmdate('c')]);
                $current->forceFill(['disk' => $targetDisk, 'conversions' => $conversions, 'processing' => false, 'data' => $data])->save();
            });
            $media->refresh();
            MediaUpdated::dispatch($media);
            // Delete only after the database points to verified remote bytes.
            self::cleanupLocalFiles($media);
        } catch (Throwable $error) {
            $current = Media::withoutGlobalScopes()->find($media->id);
            if ($current && ! self::ready($current)) {
                $attempts = (int) ($current->data[self::STATE_KEY]['attempts'] ?? 0) + 1;
                self::updateState($current, ['state' => 'pending', 'attempts' => $attempts,
                    'next_attempt_at' => time() + min(900, 30 * 2 ** min(5, $attempts - 1)),
                    'last_error_class' => get_class($error)]);
            }
            Log::warning('mixpost.staged_media.delivery_waiting', ['media_id' => $media->id, 'exception_class' => get_class($error)]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function cleanupLocalFiles(Media $media): void
    {
        foreach ($media->data[self::STATE_KEY]['local_files'] ?? [] as $file) {
            if ($file['disk'] !== self::DISK || $media->disk === self::DISK) {
                throw new \RuntimeException('Unsafe local cleanup state');
            }
            if (! Storage::disk(self::DISK)->delete($file['path'])) {
                throw new \RuntimeException('Local cleanup did not complete');
            }
        }
        foreach ($media->data[self::STATE_KEY]['replaced_files'] ?? [] as $file) {
            if ($file['disk'] === $media->disk && $file['path'] === $media->path) { continue; }
            if (! Storage::disk($file['disk'])->delete($file['path'])) { throw new \RuntimeException('Replaced file cleanup did not complete'); }
        }
        self::updateState($media, ['local_files' => [], 'replaced_files' => [], 'retained_local_files' => []]);
    }

    private static function updateState(Media $media, array $changes): void
    {
        DB::transaction(function () use ($media, $changes): void {
            $current = Media::withoutGlobalScopes()->lockForUpdate()->findOrFail($media->id);
            $data = $current->data ?? [];
            $data[self::STATE_KEY] = array_replace($data[self::STATE_KEY], $changes);
            $current->forceFill(['data' => $data])->save();
        });
        $media->refresh();
    }
}
