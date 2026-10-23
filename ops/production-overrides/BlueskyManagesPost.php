<?php

namespace Inovector\Mixpost\SocialProviders\Bluesky\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\SocialProviders\Bluesky\Helpers;
use Inovector\Mixpost\Support\FetchUrlCard;
use Inovector\Mixpost\Support\SocialProviderResponse;
use Inovector\Mixpost\Support\TemporaryFile;
use RuntimeException;

trait ManagesPost
{
    use UsesAuthServer;
    use UsesOAuthAgent;
    use UsesUploads;
    use UsesValues;

    public function publishPost(string $text, Collection $media, array $params = []): SocialProviderResponse
    {
        if ($response = $this->refreshTokenIfNeeded()) {
            return $response;
        }

        try {
            $parsed = Helpers::parseFacetsAndShortenText($text, $this->getServer());

            $postParams = [
                '$type' => 'app.bsky.feed.post',
                'text' => $parsed['text'],
                'facets' => $parsed['facets'],
                'createdAt' => Carbon::now()->utc(),
            ];

            $this->handleReplies($params, $postParams);

            $this->embedUrlCard($media, $params, $postParams);

            if ($video = $media->first(fn ($media) => $media->isVideo())) {
                $this->embedVideo($video, $postParams);
            } else {
                $this->embedImages($media->filter(fn ($media) => $media->isImage()), $postParams);
            }
        } catch (RuntimeException $e) {
            return $this->response(SocialProviderResponseStatus::ERROR, json_decode($e->getMessage(), true));
        }

        $response = $this->client()->post('com.atproto.repo.createRecord', [
            'repo' => $this->getDid(),
            'collection' => 'app.bsky.feed.post',
            'record' => $postParams,
        ]);

        return $this->buildResponse($response, function ($data) {
            // The AT-URI is the post's canonical identifier and is what insights,
            // post URLs and deletion are keyed by, so it becomes the provider_post_id.
            // The CID is only needed as a strong reference when building reply/thread links.
            return [
                'id' => $data['uri'],
                'data' => [
                    'cid' => $data['cid'],
                ],
            ];
        });
    }

    public function deletePost(string $id, array $params = []): SocialProviderResponse
    {
        if ($response = $this->refreshTokenIfNeeded()) {
            return $response;
        }

        $response = $this->client()->post('com.atproto.repo.deleteRecord', [
            'repo' => $this->getDid(),
            'collection' => 'app.bsky.feed.post',
            'rkey' => Str::afterLast($id, '/'),
        ]);

        return $this->buildResponse($response);
    }

    private function handleReplies(array $params, array &$postParams): void
    {
        // Engagement Inbox replies pass pre-resolved {uri, cid} refs (the inbound item as parent
        // within its existing thread root) rather than SocialProviderResponse objects from a thread.
        if ($refs = $params['reply_refs'] ?? null) {
            $postParams['reply'] = [
                'root' => ['uri' => $refs['root']['uri'], 'cid' => $refs['root']['cid']],
                'parent' => ['uri' => $refs['parent']['uri'], 'cid' => $refs['parent']['cid']],
            ];

            return;
        }

        /** @var SocialProviderResponse|null $root */
        $root = $params['first_response'] ?? null;

        /** @var SocialProviderResponse|null $parent */
        $parent = $params['last_response'] ?? null;

        if ($root && $parent) {
            $postParams['reply'] = [
                'root' => [
                    'uri' => $root->id(),
                    'cid' => $root->data['cid'],
                ],
                'parent' => [
                    'uri' => $parent->id(),
                    'cid' => $parent->data['cid'],
                ],
            ];
        }
    }

    private function embedImages(Collection $images, array &$postParams): void
    {
        $blobs = $images->map(function ($media) {
            $response = $this->uploadBlob($media);

            if ($response->hasError()) {
                throw new RuntimeException(json_encode($response->context()));
            }

            if (! $response->blob) {
                return null;
            }

            return [
                'image' => $response->blob,
                'alt' => $media->alt_text ?? '',
            ];
        })->filter();

        if ($blobs->isEmpty()) {
            return;
        }

        $postParams['embed'] = [
            '$type' => 'app.bsky.embed.images',
            'images' => $blobs->toArray(),
        ];
    }

    private function embedVideo(Media $video, array &$postParams): void
    {
        $response = $this->uploadBlob($video);

        if ($response->hasError()) {
            throw new RuntimeException(json_encode($response->context()));
        }

        $postParams['embed'] = [
            '$type' => 'app.bsky.embed.video',
            'video' => $response->blob,
        ];
    }

    private function embedUrlCard(Collection $media, array $params, array &$postParams): void
    {
        if ($media->count()) {
            return;
        }

        if (! $url = $params['url'] ?? '') {
            return;
        }

        $card = (new FetchUrlCard)($url);

        if (! $card['default']['title']) {
            return;
        }

        $postParams['embed'] = [
            '$type' => 'app.bsky.embed.external',
            'external' => [
                'uri' => $url,
                'title' => $card['default']['title'],
                'description' => $card['default']['description'] ?? '',
            ],
        ];

        if ($card['default']['image']) {
            // Preview images are optional. Sites can return relative URLs, SVGs,
            // inaccessible images, or content that Bluesky cannot accept.
            try {
                $image = \GuzzleHttp\Psr7\UriResolver::resolve(
                    new \GuzzleHttp\Psr7\Uri($url),
                    new \GuzzleHttp\Psr7\Uri(html_entity_decode($card['default']['image'], ENT_QUOTES | ENT_HTML5))
                );

                if (! in_array(strtolower($image->getScheme()), ['http', 'https'], true) || ! $image->getHost()) {
                    return;
                }

                // Keep the directory reachable even if fromUrl throws before returning.
                $file = TemporaryFile::make();
                try {
                    $file->fromUrl((string) $image);
                    $response = $this->uploadBlob($file);

                    if (! $response->hasError()) {
                        $postParams['embed']['external']['thumb'] = $response->blob;
                    }
                } finally {
                    $file->directory()->delete();
                }
            } catch (\Exception) {
                // Retain the link card and publish without its thumbnail.
            }
        }
    }
}
