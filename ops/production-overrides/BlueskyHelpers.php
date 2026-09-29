<?php

namespace Inovector\Mixpost\SocialProviders\Bluesky;

use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class Helpers
{
    public static function shortenUrl(string $url): string
    {
        try {
            $parsedUrl = parse_url($url);

            if (! isset($parsedUrl['scheme']) || ! in_array($parsedUrl['scheme'], ['http', 'https'])) {
                return $url;
            }

            $host = $parsedUrl['host'] ?? '';
            $path = ($parsedUrl['path'] ?? '').($parsedUrl['query'] ?? '' ? '?'.$parsedUrl['query'] : '').($parsedUrl['fragment'] ?? '' ? '#'.$parsedUrl['fragment'] : '');

            if (strlen($path) > 15) {
                return $host.mb_substr($path, 0, 13, 'UTF-8').'...';
            }

            return $host.$path;
        } catch (Exception $e) {
            return $url;
        }
    }

    public static function parseFacetsAndShortenText(string $text, string $server): array
    {
        $facets = [];
        $shortenedText = $text;
        $offset = 0;

        $urls = self::parseUrls($text);

        foreach ($urls as $url) {
            $originalUrl = $url['url'];
            $shortened = self::shortenUrl($originalUrl);
            $originalLength = strlen($originalUrl);
            $shortenedLength = strlen($shortened);
            $lengthDiff = $originalLength - $shortenedLength;
            $beforeUrl = mb_substr($shortenedText, 0, $url['start'] + $offset, '8bit');
            $afterUrl = mb_substr($shortenedText, $url['start'] + $offset + $originalLength, null, '8bit');
            $shortenedText = $beforeUrl.$shortened.$afterUrl;

            $facets[] = [
                'index' => [
                    'byteStart' => $url['start'] + $offset,
                    'byteEnd' => $url['start'] + $offset + $shortenedLength,
                ],
                'features' => [[
                    '$type' => 'app.bsky.richtext.facet#link',
                    'uri' => $originalUrl,
                ]],
            ];

            $offset -= $lengthDiff;
        }

        foreach (self::parseMentions($shortenedText) as $mention) {
            if ($did = self::resolveMention($server, $mention['handle'])) {
                $facets[] = self::mentionFacet($mention, $did);
            }
        }

        foreach (self::parseHashtags($shortenedText) as $hashtag) {
            $facets[] = [
                'index' => [
                    'byteStart' => $hashtag['start'],
                    'byteEnd' => $hashtag['end'],
                ],
                'features' => [[
                    '$type' => 'app.bsky.richtext.facet#tag',
                    'tag' => $hashtag['tag'],
                ]],
            ];
        }

        return ['text' => $shortenedText, 'facets' => $facets];
    }

    public static function parseFacets(string $text, string $server): array
    {
        $facets = [];

        foreach (self::parseMentions($text) as $mention) {
            if ($did = self::resolveMention($server, $mention['handle'])) {
                $facets[] = self::mentionFacet($mention, $did);
            }
        }

        foreach (self::parseUrls($text) as $url) {
            $facets[] = [
                'index' => ['byteStart' => $url['start'], 'byteEnd' => $url['end']],
                'features' => [[
                    '$type' => 'app.bsky.richtext.facet#link',
                    'uri' => $url['url'],
                ]],
            ];
        }

        foreach (self::parseHashtags($text) as $hashtag) {
            $facets[] = [
                'index' => ['byteStart' => $hashtag['start'], 'byteEnd' => $hashtag['end']],
                'features' => [[
                    '$type' => 'app.bsky.richtext.facet#tag',
                    'tag' => $hashtag['tag'],
                ]],
            ];
        }

        return $facets;
    }

    /**
     * A mention is optional enrichment. Retry a transient lookup twice, then
     * publish the post as plain text if Bluesky's identity service is unavailable.
     */
    private static function resolveMention(string $server, string $handle): ?string
    {
        try {
            $response = Http::retry(3, 1000)->timeout(5)->get("$server/xrpc/com.atproto.identity.resolveHandle", [
                'handle' => $handle,
            ]);
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() ? $response->json('did') : null;
    }

    private static function mentionFacet(array $mention, string $did): array
    {
        return [
            'index' => ['byteStart' => $mention['start'], 'byteEnd' => $mention['end']],
            'features' => [['$type' => 'app.bsky.richtext.facet#mention', 'did' => $did]],
        ];
    }

    public static function parseMentions(string $text): array
    {
        $spans = [];
        $mentionRegex = '/[\$|\W](@([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)/u';

        if (preg_match_all($mentionRegex, $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $match) {
                $spans[] = [
                    'start' => $match[1],
                    'end' => $match[1] + strlen($match[0]),
                    'handle' => substr($match[0], 1),
                ];
            }
        }

        return $spans;
    }

    public static function parseUrls(string $text): array
    {
        $spans = [];
        $urlRegex = '/[\$|\W](https?:\/\/(www\.)?[-a-zA-Z0-9@:%._\+~#=]{1,256}\.[a-zA-Z0-9()]{1,6}\b([-a-zA-Z0-9()@:%_\+.~#?&\/=-]*[-a-zA-Z0-9@%_\+~#\/=-])?)/u';

        if (preg_match_all($urlRegex, $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $match) {
                $spans[] = [
                    'start' => $match[1],
                    'end' => $match[1] + strlen($match[0]),
                    'url' => $match[0],
                ];
            }
        }

        return $spans;
    }

    public static function parseHashtags(string $text): array
    {
        $spans = [];
        $hashtagRegex = '/(?:^|\s)(#[^\d\s]\S*)(?=\s|$)/u';

        if (preg_match_all($hashtagRegex, $text, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $match) {
                $tag = trim($match[0]);
                $tag = preg_replace('/\p{P}+$/u', '', $tag);

                if (mb_strlen($tag) > 66) {
                    continue;
                }

                $spans[] = [
                    'start' => $match[1],
                    'end' => $match[1] + mb_strlen($tag, 'UTF-8'),
                    'tag' => ltrim($tag, '#'),
                ];
            }
        }

        return $spans;
    }
}
