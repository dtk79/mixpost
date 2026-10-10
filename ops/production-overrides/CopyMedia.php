<?php

namespace Inovector\Mixpost\Actions\Media;

use Illuminate\Support\Arr;
use Inovector\Mixpost\Jobs\ConvertMediaVideoJob;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Support\MediaFilesystem;
use Throwable;

class CopyMedia
{
    /**
     * Duplicates each file into a folder, bytes included, so the two entries share nothing:
     * deleting one leaves the other's file where it is.
     *
     * @param  array<int, int|string>  $ids
     * @return array<int, Media>
     */
    public function __invoke(array $ids, ?int $folderId = null): array
    {
        $copies = [];

        foreach (Media::whereIn('id', $ids)->get() as $media) {
            $lock = null;
            try {
                if (isset($media->data['peachy_storage'])) {
                    $lock = fopen(storage_path('app/peachy-upload-state/locks/'.$media->id.'.lock'), 'c');
                    if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
                        throw new \RuntimeException('Media is being prepared; retry copying after preparation');
                    }
                    $media->refresh();
                    if ($media->disk === \Inovector\Mixpost\Support\LocalMediaStaging::DISK) {
                        \Inovector\Mixpost\Support\LocalMediaStaging::ensureCapacity($media->size_total);
                    }
                }
                $copy = $this->copy($media, $folderId);
            } catch (Throwable $exception) {
                // One unreadable file is not a reason to abandon the rest of the selection.
                report($exception);

                continue;
            } finally {
                if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
            }

            if (! $copy) {
                continue;
            }

            $copies[] = $copy;
        }

        return $copies;
    }

    protected function copy(Media $media, ?int $folderId): ?Media
    {
        // An external file is an address rather than bytes we hold, so both entries point at it.
        if ($media->disk === 'external_media') {
            return $this->insert($media, $folderId, $media->path, $media->conversions ?? []);
        }

        $path = $this->siblingPath($media->path);

        if (! MediaFilesystem::copyBetweenDisks($media->disk, $media->path, $media->disk, $path)) {
            return null;
        }

        return $this->insert($media, $folderId, $path, $this->copyConversions($media));
    }

    protected function copyConversions(Media $media): array
    {
        $conversions = [];

        foreach ($media->conversions ?? [] as $conversion) {
            $path = $this->siblingPath($conversion['path']);

            $copied = MediaFilesystem::copyBetweenDisks(
                $conversion['disk'],
                $conversion['path'],
                $conversion['disk'],
                $path
            );

            if (! $copied) {
                continue;
            }

            $conversions[] = array_merge($conversion, ['path' => $path]);
        }

        return $conversions;
    }

    protected function insert(Media $media, ?int $folderId, string $path, array $conversions): Media
    {
        $data = Arr::except($media->data ?? [], ['adobe_express_doc_id', 'peachy_storage']);
        if (isset($media->data['peachy_storage']) && $media->disk === \Inovector\Mixpost\Support\LocalMediaStaging::DISK) {
            $data['peachy_storage'] = ['state' => 'pending', 'target_disk' => $media->data['peachy_storage']['target_disk'],
                'upload_uuid' => null, 'attempts' => 0, 'next_attempt_at' => 0];
        }
        if (isset($data['peachy_storage'])) {
            foreach (array_merge([['path' => $path]], $conversions) as $file) {
                $handle = fopen(\Illuminate\Support\Facades\Storage::disk($media->disk)->path($file['path']), 'r+');
                try { if (! $handle || ! fsync($handle)) { throw new \RuntimeException('Cannot sync copied file'); } }
                finally { if ($handle) { fclose($handle); } }
            }
        }
        $copy = Media::create([
            'name' => $media->name,
            'mime_type' => $media->mime_type,
            'folder_id' => $folderId,
            'disk' => $media->disk,
            'path' => $path,
            // The Adobe Express document stays with the original: the editor finds media by that
            // id and would otherwise write its next version over whichever entry it matched first.
            'data' => $data ?: null,
            'size' => $media->size,
            'size_total' => $media->size + array_sum(array_column($conversions, 'size')),
            'conversions' => $conversions,
            'processing' => $media->processing,
        ]);

        // A video copied mid-conversion carries the original upload, so the copy is transcoded
        // and given a thumbnail of its own.
        if (! \Inovector\Mixpost\Support\LocalMediaStaging::ready($copy)) {
            \Inovector\Mixpost\Support\LocalMediaStaging::enqueue($copy);
        } elseif ($copy->isProcessing()) {
            ConvertMediaVideoJob::dispatch($copy);
        }

        return $copy;
    }

    /**
     * Next to the original under a name of its own, so neither entry can overwrite the other.
     */
    protected function siblingPath(string $path): string
    {
        $directory = pathinfo($path, PATHINFO_DIRNAME);
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        $filename = bin2hex(random_bytes(20)).($extension ? ".$extension" : '');

        return $directory === '.' ? $filename : "$directory/$filename";
    }
}
