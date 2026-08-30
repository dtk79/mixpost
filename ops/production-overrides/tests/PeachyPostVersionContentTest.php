<?php

$helperPath = getenv('PEACHY_POST_VERSION_CONTENT_PATH') ?: dirname(__DIR__).'/PeachyPostVersionContent.php';

require $helperPath;

use Inovector\Mixpost\Support\PeachyPostVersionContent;

function expectSame(array $expected, array $actual, string $case): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "Failed: {$case}\nExpected: ".json_encode($expected)."\nActual: ".json_encode($actual)."\n");
        exit(1);
    }
}

$main = ['body' => '<div>Main post</div>', 'media' => [123], 'url' => null, 'video_thumbs' => []];
$reply = ['body' => '<div>Valid reply</div>', 'media' => [], 'url' => null, 'video_thumbs' => []];
$blank = ['body' => '<div><br></div>&nbsp;'."\u{200B}", 'media' => [], 'url' => null, 'video_thumbs' => []];
$urlOnly = ['body' => '', 'media' => [], 'url' => 'https://example.com', 'video_thumbs' => []];
$mediaOnly = ['body' => '', 'media' => [456], 'url' => null, 'video_thumbs' => []];
$thumbOnly = ['body' => '', 'media' => [], 'url' => null, 'video_thumbs' => [['media_id' => 1, 'thumb_id' => 2]]];

expectSame([$blank], PeachyPostVersionContent::withoutEmptyAdditionalItems([$blank]), 'preserves the required first item');
expectSame([$main, $reply], PeachyPostVersionContent::withoutEmptyAdditionalItems([$main, $blank, $reply, $blank]), 'removes empty additional items');
expectSame([$main, $urlOnly, $mediaOnly, $thumbOnly], PeachyPostVersionContent::withoutEmptyAdditionalItems([$main, $urlOnly, $mediaOnly, $thumbOnly]), 'preserves non-text content');

echo "PeachyPostVersionContent tests passed\n";
