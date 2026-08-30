<?php

namespace Inovector\Mixpost\Support;

final class PeachyPostVersionContent
{
    public static function withoutEmptyAdditionalItems(array $content): array
    {
        if (count($content) <= 1) {
            return array_values($content);
        }

        $first = array_shift($content);

        $additional = array_filter($content, function ($item) {
            return ! is_array($item) || ! self::isEmpty($item);
        });

        return array_values(array_merge([$first], $additional));
    }

    public static function isEmpty(array $item): bool
    {
        $body = html_entity_decode(
            strip_tags((string) ($item['body'] ?? '')),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );

        $body = preg_replace('/[\s\p{Z}\x{200B}\x{FEFF}]+/u', '', $body) ?? trim($body);

        return $body === ''
            && empty($item['media'] ?? [])
            && trim((string) ($item['url'] ?? '')) === ''
            && empty($item['video_thumbs'] ?? []);
    }
}
