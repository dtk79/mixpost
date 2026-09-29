<?php

namespace Inovector\Mixpost\SocialProviders\Twitter\Concerns;

trait ManagesPostImport
{
    public function fetchPost(string $providerPostId): ?array
    {
        $response = $this->connection->get("tweets/$providerPostId", [
            'tweet.fields' => 'text,attachments,author_id,created_at,public_metrics',
            'expansions' => 'attachments.media_keys',
            'media.fields' => 'type,url,preview_image_url',
        ]);

        if (! isset($response->data)) {
            return null;
        }

        $tweet = $response->data;
        $media = $this->resolveFetchedMedia($response->includes ?? null, $tweet);

        return [
            'text' => $tweet->text ?? '',
            'url' => "https://x.com/i/status/{$tweet->id}",
            'thumbnail' => $media['thumbnail'],
            'content_type' => $media['type'],
            'author_id' => $tweet->author_id ?? null,
            'created_at' => $tweet->created_at ?? null,
            'public_metrics' => $tweet->public_metrics ?? null,
        ];
    }

    protected function resolveFetchedMedia($includes, $tweet): array
    {
        $mediaKeys = $tweet->attachments->media_keys ?? [];

        if (empty($mediaKeys) || ! is_object($includes) || ! isset($includes->media)) {
            return ['thumbnail' => null, 'type' => 'text'];
        }

        foreach ($includes->media as $media) {
            if (($media->media_key ?? null) === $mediaKeys[0]) {
                return [
                    'thumbnail' => $media->url ?? $media->preview_image_url ?? null,
                    'type' => $media->type ?? 'text',
                ];
            }
        }

        return ['thumbnail' => null, 'type' => 'text'];
    }
}
