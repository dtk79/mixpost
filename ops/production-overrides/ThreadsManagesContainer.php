<?php

namespace Inovector\Mixpost\SocialProviders\Threads\Concerns;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Support\SocialProviderResponse;

trait ManagesContainer
{
    public function createContainer(?Media $mediaItem = null, array $data = []): SocialProviderResponse
    {
        if (! Arr::has($data, 'media_type')) {
            $data['media_type'] = $mediaItem ? ($mediaItem->isVideo() ? 'VIDEO' : 'IMAGE') : 'TEXT';
        }

        if ($mediaItem) {
            if ($mediaItem->isVideo()) {
                $videoUrl = $this->threadsVideoUrl($mediaItem);

                if (! $videoUrl) {
                    return $this->response(SocialProviderResponseStatus::ERROR, ['social_video_optimization_pending']);
                }

                $data['video_url'] = $videoUrl;
            } else {
                $data['image_url'] = $mediaItem->getUrl();
            }
        }

        if ($mediaItem && $mediaItem->alt_text) {
            $data['alt_text'] = $mediaItem->alt_text;
        }

        return $this->buildResponse(
            $this->http()::withToken($this->accessToken())
                ->post("$this->graphUrl/$this->graphVersion/{$this->values['provider_id']}/threads", $data)
        );
    }

    private function threadsVideoUrl(Media $mediaItem): ?string
    {
        // Match the shared video selection used by Instagram Reels, including older conversions.
        if ($url = $mediaItem->getConversionUrl('social_video')) {
            return $url;
        }

        if ($url = $mediaItem->getConversionUrl('instagram_reel')) {
            return $url;
        }

        if ($mediaItem->size > 300 * 1024 * 1024) {
            return null;
        }

        return $mediaItem->getUrl();
    }

    public function getContainer(string $containerId): SocialProviderResponse
    {
        return $this->buildResponse(
            Http::withToken($this->accessToken())
                ->get("$this->graphUrl/$this->graphVersion/$containerId", [
                    'fields' => 'status,error_message',
                ])
        );
    }

    public function publishContainer(string $creationId, array $data = []): SocialProviderResponse
    {
        return $this->buildResponse(
            $this->http()::withToken($this->accessToken())
                ->post("$this->graphUrl/$this->graphVersion/{$this->values['provider_id']}/threads_publish",
                    array_merge(['creation_id' => $creationId], $data)
                )
        );
    }
}
