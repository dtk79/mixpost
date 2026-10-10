<?php

namespace Inovector\Mixpost\Support;

use Inovector\Mixpost\Models\Media;
use RuntimeException;

class LocalChunkedUpload extends ChunkedUpload
{
    public function __construct()
    {
        parent::__construct();
        if (LocalMediaStaging::enabled()) { $this->disk = LocalMediaStaging::DISK; }
    }
    protected function getTempDirectory(string $uploadUuid): string
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uploadUuid)) {
            throw new RuntimeException('Invalid upload session');
        }
        $persistent = storage_path('app/peachy-upload-state/chunked/'.$uploadUuid);
        $legacy = parent::getTempDirectory($uploadUuid);
        return ! is_dir($persistent) && is_dir($legacy) ? $legacy : $persistent;
    }
    public function getSessionData(string $uploadUuid): array
    {
        $session = parent::getSessionData($uploadUuid);
        $this->disk = $session['disk']; // Keep already-open S3 uploads on their original route.
        return $session;
    }
    public function initiate(string $filename, string $mimeType, int $totalSize, array $metadata = []): array
    {
        LocalMediaStaging::ensureCapacity($totalSize);
        return parent::initiate($filename, $mimeType, $totalSize, $metadata);
    }
    public function uploadChunk(string $uploadUuid, int $chunkIndex, \Illuminate\Http\UploadedFile $chunk): array
    {
        LocalMediaStaging::ensureCapacity($chunk->getSize());
        $result = parent::uploadChunk($uploadUuid, $chunkIndex, $chunk);
        touch($this->getTempDirectory($uploadUuid).'/session.json');
        return $result;
    }
    public function completeAndStore(string $uuid, callable $store): Media
    {
        return $this->withSessionLock($uuid, function (array $session) use ($uuid, $store): Media {
            if (! empty($session['accepted_media_id'])) {
                $accepted = Media::withoutGlobalScopes()->where('workspace_id', $session['workspace_id'])->find($session['accepted_media_id']);
                if ($accepted) { return $accepted; }
                throw new RuntimeException('Accepted upload was removed; start a new upload');
            }
            $existing = Media::withoutGlobalScopes()->where('workspace_id', $session['workspace_id'])
                ->where('data->peachy_storage->upload_uuid', $uuid)->first();
            if ($existing) { return $existing; }
            LocalMediaStaging::ensureCapacity($session['total_size']);
            $source = $session['is_s3'] ? $this->completeS3($uuid, $session) : $this->completeLocal($uuid, $session);
            $media = $store($source, $session['is_s3'] ? null : $uuid);
            $session['accepted_media_id'] = $media->id;
            $this->saveSessionData($uuid, $session);
            if ($session['is_s3']) {
                // Preserve the existing legacy route's temp-object cleanup.
                $this->cleanup($uuid);
                return $media;
            }
            // Keep the small checkpoint for idempotent completion; discard only chunk fixtures.
            foreach (glob($this->getTempDirectory($uuid).'/chunk_*') as $chunk) { unlink($chunk); }
            @unlink($this->getTempDirectory($uuid).'/assembled');
            return $media;
        });
    }
    public static function pruneExpired(): void
    {
        foreach (glob(storage_path('app/peachy-upload-state/chunked/*/session.json')) as $session) {
            if (filemtime($session) > time() - 86400) { continue; }
            $lock = @fopen(dirname($session).'/session.lock', 'c');
            if (! $lock) { continue; }
            try {
                if (flock($lock, LOCK_EX | LOCK_NB) && filemtime($session) <= time() - 86400) {
                    foreach (glob(dirname($session).'/*') as $file) { if (is_file($file)) { @unlink($file); } }
                    @rmdir(dirname($session));
                }
            } finally { flock($lock, LOCK_UN); fclose($lock); }
        }
    }
}
