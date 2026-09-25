<?php
require '/var/www/html/vendor/autoload.php';
$app=require '/var/www/html/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(Throwable $e){fwrite(STDERR,(string)$e);exit(1);});
require __DIR__.'/../MediaSocialVideoConversion.php';
$root='/tmp/social-video-duration-'.bin2hex(random_bytes(5));mkdir($root);
config(['filesystems.disks.video_regression'=>['driver'=>'local','root'=>$root,'throw'=>true]]);
try {
    $cmd='ffmpeg -v error -f lavfi -i color=c=black:s=160x240:r=24 -f lavfi -i anullsrc=r=44100:cl=mono -t 91 -c:v libx264 -threads 1 -c:a aac '.escapeshellarg($root.'/source.mp4');
    exec($cmd,$lines,$code);if($code!==0)throw new RuntimeException('Fixture creation failed');
    $conversion=Inovector\Mixpost\MediaConversions\MediaSocialVideoConversion::name('social_video')->filepath('source.mp4')->fromDisk('video_regression')->perform();
    $path=$root.'/'.$conversion->get()['path'];
    $probe=json_decode(shell_exec('ffprobe -v error -show_entries format=duration:stream=codec_name,width,height,pix_fmt,r_frame_rate,sample_rate,channels -of json '.escapeshellarg($path)),true);
    if(abs((float)$probe['format']['duration']-91)>0.1)throw new RuntimeException('Full duration was not preserved');
    $v=$probe['streams'][0];$a=$probe['streams'][1];
    if($v['codec_name']!=='h264'||$v['pix_fmt']!=='yuv420p'||$v['r_frame_rate']!=='30/1'||$a['codec_name']!=='aac'||$a['sample_rate']!=='48000'||$a['channels']!==2)throw new RuntimeException('Unexpected output profile');
    echo 'PASS: 91-second video retains full duration with H.264, 30 FPS, AAC 48kHz stereo'.PHP_EOL;
} finally { foreach(glob($root.'/*') as $file)unlink($file);rmdir($root); }
