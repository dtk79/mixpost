<?php
// Read-only runtime probe: no provider calls, publishing, or notification dispatch.
require '/var/www/html/vendor/autoload.php';
$app=require '/var/www/html/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);});
use Illuminate\Support\Facades\DB;
$providers=Inovector\Mixpost\Facades\SocialProviderManager::providers();
foreach($providers as $key=>$class){if(!class_exists($class))throw new RuntimeException('Provider missing: '.$key);}
$counts=[];
foreach(['users','mixpost_workspaces','mixpost_accounts','mixpost_posts','mixpost_post_accounts','mixpost_media'] as $table)$counts[$table]=DB::table($table)->count();
$health=app('router')->dispatch(Illuminate\Http\Request::create('/api/health'));
if($health->getStatusCode()!==200 || !str_contains($health->getContent(),'"ok":true'))throw new RuntimeException('Health route failed');
$manifest=json_decode(file_get_contents('/var/www/html/public/vendor/mixpost/manifest.json'),true);
$missing=[];
foreach($manifest as $entry)foreach(array_merge([$entry['file']??null],$entry['css']??[],$entry['assets']??[]) as $file)if($file&&!is_file('/var/www/html/public/vendor/mixpost/'.$file))$missing[]=$file;
if($missing)throw new RuntimeException('Missing packaged assets: '.count($missing));
$package='inovector/mixpost-pro-team';
echo json_encode(['version'=>Composer\InstalledVersions::getPrettyVersion($package),'source'=>Composer\InstalledVersions::getReference($package),'lockSha256'=>hash_file('sha256','/var/www/html/composer.lock'),'providers'=>count($providers),'coreCounts'=>$counts,'health'=>$health->getStatusCode(),'missingAssets'=>count($missing),'nextScheduled'=>DB::table('mixpost_posts')->where('status',1)->whereNull('deleted_at')->min('scheduled_at')],JSON_PRETTY_PRINT).PHP_EOL;
