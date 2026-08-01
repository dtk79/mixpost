<?php

namespace Inovector\Mixpost\SocialProviders\Bluesky\Concerns;

use Inovector\Mixpost\Concerns\UsesImageManager;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Support\SocialProviderResponse;
use Inovector\Mixpost\Support\TemporaryFile;
use Inovector\Mixpost\Util;

trait UsesUploads
{
    use UsesImageManager;
    use UsesOAuthAgent;
    use UsesResponseBuilder;

    /**
     * @see https://docs.bsky.app/docs/api/com-atproto-repo-upload-blob
     */
    private const BLUESKY_MAX_BLOB_SIZE = 976560; // ~0.93 MB

    public function uploadBlob(Media|TemporaryFile $media): SocialProviderResponse
    {
        $media = $this->blueskyMediaItemForUpload($media);

        $readStream = match (true) {
            $media instanceof Media => $media->readStream(),
            $media instanceof TemporaryFile => ['stream' => $media->readStream(), 'temporaryDirectory' => $media->directory()],
        };

        $mimeType = match (true) {
            $media instanceof Media => $media->mime_type,
            $media instanceof TemporaryFile => $media->mimeType(),
        };

        if ($this->isCompressibleImage($mimeType)) {
            $contents = stream_get_contents($readStream['stream']);

            Util::closeAndDeleteStreamResource($readStream);

            if (strlen($contents) > self::BLUESKY_MAX_BLOB_SIZE) {
                $compressed = $this->compressImageForBluesky($contents);
                $body = $compressed['contents'];
                $mimeType = $compressed['mimeType'];
            } else {
                $body = $contents;
            }
        } else {
            $body = $readStream['stream'];
        }

        $response = $this->client()
            ->withBody($body, $mimeType)
            ->post('com.atproto.repo.uploadBlob');

        if (is_resource($readStream['stream'] ?? null)) {
            Util::closeAndDeleteStreamResource($readStream);
        }

        return $this->buildResponse($response);
    }

    private function blueskyMediaItemForUpload(Media|TemporaryFile $media): Media|TemporaryFile
    {
        if (! $media instanceof Media || ! $media->isVideo()) {
            return $media;
        }

        $conversion = $media->getConversion('social_video')
            ?: $media->getConversion('instagram_reel');

        if (! $conversion) {
            return $media;
        }

        $conversionSize = (int) ($conversion['size'] ?? 0);
        $sourceIsMp4 = $media->mime_type === 'video/mp4';

        if ($sourceIsMp4 && ($conversionSize < 1 || $conversionSize >= (int) $media->size)) {
            return $media;
        }

        $uploadItem = $media->replicate();
        $uploadItem->forceFill([
            'id' => $media->id,
            'disk' => $conversion['disk'],
            'path' => $conversion['path'],
            'size' => $conversionSize ?: $media->size,
            'size_total' => $conversionSize ?: $media->size,
            'mime_type' => 'video/mp4',
        ]);

        return $uploadItem;
    }

    private function isCompressibleImage(string $mimeType): bool
    {
        return in_array($mimeType, [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/webp',
            'image/gif',
        ], true);
    }

    private function compressImageForBluesky(string $contents): array
    {
        $image = $this->imageManager()->read($contents);

        foreach ([85, 75, 65, 50, 40] as $quality) {
            $compressed = $image->toJpeg(quality: $quality)->toString();

            if (strlen($compressed) <= self::BLUESKY_MAX_BLOB_SIZE) {
                return ['contents' => $compressed, 'mimeType' => 'image/jpeg'];
            }
        }

        $width = $image->width();

        foreach ([0.75, 0.5, 0.4] as $scale) {
            $compressed = $this->imageManager()
                ->read($contents)
                ->scaleDown(width: (int) ($width * $scale))
                ->toJpeg(quality: 70)
                ->toString();

            if (strlen($compressed) <= self::BLUESKY_MAX_BLOB_SIZE) {
                return ['contents' => $compressed, 'mimeType' => 'image/jpeg'];
            }
        }

        return [
            'contents' => $this->imageManager()
                ->read($contents)
                ->scaleDown(width: 800)
                ->toJpeg(quality: 60)
                ->toString(),
            'mimeType' => 'image/jpeg',
        ];
    }
}
