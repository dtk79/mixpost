<?php

// Run against exact Pro dependencies, without application boot or network access.
require '/var/www/html/vendor/autoload.php';

use FFMpeg\Coordinate\TimeCode;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe\DataMapping\Stream;
use Inovector\Mixpost\MediaConversions\MediaVideoThumbConversion;
use Inovector\Mixpost\Support\VideoProbe;

set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, (string) $error."\n");
    exit(1);
});

$dimensions = new ReflectionMethod(VideoProbe::class, 'dimensions');
$run = function (string $name, callable $test): void {
    $test();
    echo 'PASS '.$name.PHP_EOL;
};
$assert = function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

foreach ([
    ['square pixels landscape', ['width'=>1920, 'height'=>1080, 'sample_aspect_ratio'=>'1:1'], [1920,1080]],
    ['square pixels portrait', ['width'=>1080, 'height'=>1920, 'sample_aspect_ratio'=>'1:1'], [1080,1920]],
    ['original HEVC portrait', ['width'=>2156, 'height'=>2160, 'sample_aspect_ratio'=>'9:16'], [2156,3840]],
    ['uploaded anamorphic portrait', ['width'=>1920, 'height'=>1080, 'sample_aspect_ratio'=>'1617:5120'], [1920,3420]],
    ['wide rectangular pixels', ['width'=>720, 'height'=>576, 'sample_aspect_ratio'=>'16:15'], [768,576]],
    ['display ratio fallback', ['width'=>1920, 'height'=>1080, 'display_aspect_ratio'=>'539:960'], [1920,3420]],
    ['unknown ratios preserve coded size', ['width'=>640, 'height'=>480, 'sample_aspect_ratio'=>'N/A'], [640,480]],
    ['zero ratio does not divide by zero', ['width'=>640, 'height'=>480, 'sample_aspect_ratio'=>'0:1', 'display_aspect_ratio'=>'1:0'], [640,480]],
    ['rotation tag after pixel correction', ['width'=>2156, 'height'=>2160, 'sample_aspect_ratio'=>'9:16', 'tags'=>['rotate'=>'90']], [3840,2156]],
    ['side-data rotation after pixel correction', ['width'=>2156, 'height'=>2160, 'sample_aspect_ratio'=>'9:16', 'side_data_list'=>[['rotation'=>-90]]], [3840,2156]],
] as [$name,$data,$expected]) {
    $run($name, function () use ($dimensions,$data,$expected,$assert): void {
        $actual = $dimensions->invoke(null, new Stream($data));
        $assert($actual === ['width'=>$expected[0], 'height'=>$expected[1]], json_encode([$actual,$expected]));
    });
}
$run('missing video dimensions stay unknown', function () use ($dimensions,$assert): void {
    $assert($dimensions->invoke(null, null) === ['width'=>null,'height'=>null], 'Missing stream must stay unknown');
});

$root = '/tmp/video-aspect-'.bin2hex(random_bytes(6));
$filter = (new ReflectionMethod(MediaVideoThumbConversion::class, 'thumbnailFilter'))->invoke(null);
mkdir($root);
$ffmpeg = FFMpeg::create(['ffmpeg.binaries'=>'/usr/bin/ffmpeg','ffprobe.binaries'=>'/usr/bin/ffprobe']);
$command = function (array $arguments) use ($assert): void {
    exec(implode(' ', array_map('escapeshellarg', $arguments)).' 2>&1', $output, $code);
    $assert($code === 0, implode("\n", $output));
};
$checkFrame = function (string $path) use ($ffmpeg,$filter,$root,$assert): void {
    $stream = $ffmpeg->getFFProbe()->streams($path)->videos()->first();
    $expected = (new ReflectionMethod(VideoProbe::class, 'dimensions'))->invoke(null, $stream);
    $jpeg = $root.'/'.bin2hex(random_bytes(5)).'.jpg';
    $frame = $ffmpeg->open($path)->frame(TimeCode::fromSeconds(0));
    $frame->filters()->custom($filter);
    $frame->save($jpeg);
    [$width,$height] = getimagesize($jpeg);
    $ratio = $expected['width']/$expected['height'];
    $assert($width <= 430 && $height <= 430, 'Thumbnail exceeds bounding box');
    $assert(abs(($width/$height)/$ratio-1) < 0.015, 'Thumbnail must preserve displayed proportions');
    $assert($width <= $expected['width'] && $height <= $expected['height'], 'Small source must not be enlarged');
};
try {
    foreach ([
        ['anamorphic landscape storage to portrait display','320x180','81/256'],
        ['standard landscape thumbnail','320x180','1'],
        ['standard portrait thumbnail','120x240','1'],
        ['wide pixel thumbnail','320x180','2'],
    ] as $i=>[$name,$size,$sar]) {
        $path = $root.'/fixture-'.$i.'.mp4';
        $command(['/usr/bin/ffmpeg','-v','error','-f','lavfi','-i','testsrc2=size='.$size.':rate=1','-t','2','-vf','setsar='.$sar,'-c:v','libx264','-threads','1',$path]);
        $run($name, fn () => $checkFrame($path));
    }
    $rotated = $root.'/rotated.mp4';
    $command(['/usr/bin/ffmpeg','-v','error','-i',$root.'/fixture-2.mp4','-c','copy','-metadata:s:v:0','rotate=90',$rotated]);
    $run('rotated phone thumbnail', fn () => $checkFrame($rotated));
    if (($original = getenv('VIDEO_ASPECT_ORIGINAL')) !== false) {
        $run('user original thumbnail', fn () => $checkFrame($original));
    }
} finally {
    foreach (glob($root.'/*') as $file) {
        unlink($file);
    }
    rmdir($root);
}
