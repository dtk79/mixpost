<?php

// Explicit operator-run, resumable S3 version cleanup. No credentials are persisted.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Aws\CommandPool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

$mode = $argv[1] ?? 'refs';
$run = $argv[2] ?? 'thumbnail-cleanup-20260906';
if (! preg_match('/^[a-z0-9-]+$/', $run)) throw new RuntimeException('Invalid run name');
$dir = '/var/www/html/storage/app/peachy-storage-cleanup/'.$run;
if (! is_dir($dir)) mkdir($dir, 0700, true);
$runLock = fopen($dir.'/run.lock', 'c');
if (! flock($runLock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Another process owns this run');
$client = Storage::disk('s3')->getClient();
$bucket = config('filesystems.disks.s3.bucket');
if ($bucket !== 'ducati-mixpost') throw new RuntimeException('Unexpected bucket');

function saveJson(string $path, $data): void {
    file_put_contents($path.'.tmp', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    rename($path.'.tmp', $path);
}
function readJson(string $path): array {
    return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
}
function extractPaths(string $value): array {
    $value = rawurldecode(str_replace('\\/', '/', $value));
    preg_match_all('~imported/[a-zA-Z0-9._/-]+~', $value, $m);
    return $m[0];
}
function references(): array {
    $columns = DB::select("SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND DATA_TYPE IN ('char','varchar','tinytext','text','mediumtext','longtext','json')");
    $tables=[];$paths=[];$hits=[];
    foreach($columns as $c) $tables[$c->TABLE_NAME][]=$c->COLUMN_NAME;
    foreach($tables as $table=>$cols) {
        // No raw values are logged; only paths in the cleanup prefix are retained.
        $query=DB::table($table)->select($cols)->where(function($q)use($cols){foreach($cols as $c)$q->orWhere($c,'like','%imported%');});
        foreach($query->cursor() as $row) foreach((array)$row as $col=>$value) {
            foreach(extractPaths((string)$value) as $path){$paths[$path]=true;$hits[$table.'.'.$col]=($hits[$table.'.'.$col]??0)+1;}
        }
    }
    return ['paths'=>$paths,'sources'=>$hits,'at'=>gmdate('c')];
}
function recentThumbnailReferences(array $paths): array {
    foreach(DB::table('mixpost_imported_posts')->pluck('thumbnail') as $v)foreach(extractPaths((string)$v) as $p)$paths[$p]=true;
    return $paths;
}

if ($mode === 'refs') {
    $refs=references();saveJson($dir.'/references.json',$refs);
    echo json_encode(['paths'=>count($refs['paths']),'sources'=>$refs['sources']])."\n";
} elseif ($mode === 'plan' || $mode === 'pilot-plan') {
    if(file_exists($dir.'/plan.json') || is_dir($dir.'/batches'))throw new RuntimeException('Plan already exists');
    $refs=references();saveJson($dir.'/references.json',$refs);
    $cutoff=time()-3600; // Protect recent/in-flight files even if not yet referenced.
    mkdir($dir.'/batches',0700);$batch=[];$batchNo=0;$count=0;$bytes=0;$skipped=[];$seen=[];
    $p=['Bucket'=>$bucket,'Prefix'=>'imported/','MaxKeys'=>1000];$complete=false;
    for($page=0;$page<20000;$page++) {
        $r=$client->listObjectVersions($p);
        foreach($r['Versions']??[] as $v){
            $key=$v['Key'];$why=null;
            if(isset($refs['paths'][$key]))$why='referenced';
            elseif(!preg_match('~^imported/[a-f0-9-]{36}/[a-f0-9-]{36}/[a-f0-9]{31,64}\\.jpg$~',$key))$why='unrecognized_path';
            elseif($v['LastModified']->getTimestamp()>=$cutoff)$why='recent';
            if($why){$skipped[$why]=($skipped[$why]??0)+1;continue;}
            $batch[]=['Key'=>$key,'VersionId'=>$v['VersionId'],'Size'=>(int)$v['Size']];$count++;$bytes+=(int)$v['Size'];
            if(count($batch)===($mode==='pilot-plan'?100:1000)){saveJson($dir.'/batches/'.sprintf('%06d',$batchNo++).'.json',$batch);$batch=[];}
            if($mode==='pilot-plan' && $count>=1000)break 2;
        }
        if($page%100===0){$progress=['page'=>$page,'candidates'=>$count,'bytes'=>$bytes,'skipped'=>$skipped];saveJson($dir.'/progress.json',$progress);echo json_encode($progress)."\n";}
        if(!$r['IsTruncated']){$complete=true;break;}
        $p['KeyMarker']=$r['NextKeyMarker'];$p['VersionIdMarker']=$r['NextVersionIdMarker'];$token=$p['KeyMarker'].'|'.$p['VersionIdMarker'];
        if(isset($seen[$token]))throw new RuntimeException('Repeated pagination marker');$seen[$token]=true;
    }
    if(!$complete && $mode!=='pilot-plan')throw new RuntimeException('Incomplete inventory');
    if($batch)saveJson($dir.'/batches/'.sprintf('%06d',$batchNo++).'.json',$batch);
    $plan=['bucket'=>$bucket,'at'=>gmdate('c'),'cutoff'=>gmdate('c',$cutoff),'complete'=>$complete,'pilot'=>$mode==='pilot-plan','versions'=>$count,'bytes'=>$bytes,'batches'=>$batchNo,'skipped'=>$skipped];
    saveJson($dir.'/plan.json',$plan);echo 'PLAN '.json_encode($plan)."\n";
} elseif ($mode === 'delete') {
    $plan=readJson($dir.'/plan.json');
    if(!$plan['complete'] && !$plan['pilot'])throw new RuntimeException('Incomplete plan');
    foreach(['receipts','errors'] as $sub)if(!is_dir($dir.'/'.$sub))mkdir($dir.'/'.$sub,0700);
    $refs=references();$protected=$refs['paths']+readJson($dir.'/references.json')['paths'];saveJson($dir.'/references-at-delete.json',$refs);
    $files=glob($dir.'/batches/*.json');sort($files);$selected=[];$stopped=false;$started=microtime(true);
    $concurrency=max(1,min(128,(int)($argv[3]??16)));
    $commands=function()use($files,$dir,$client,$bucket,&$protected,&$selected,&$stopped){
        foreach($files as $file){
            if($stopped)break;
            $id=basename($file,'.json');if(file_exists($dir.'/receipts/'.$id.'.json'))continue;
            $protected=recentThumbnailReferences($protected);$objects=[];$bytes=0;$skipped=0;
            foreach(readJson($file) as $v){
                if(isset($protected[$v['Key']])){$skipped++;continue;}
                if(!str_starts_with($v['Key'],'imported/') || !isset($v['VersionId']))throw new RuntimeException('Invalid manifest object');
                $objects[]=['Key'=>$v['Key'],'VersionId'=>$v['VersionId']];$bytes+=$v['Size'];
            }
            $selected[$id]=['count'=>count($objects),'bytes'=>$bytes,'protected_since_plan'=>$skipped];
            if(!$objects){saveJson($dir.'/receipts/'.$id.'.json',$selected[$id]);continue;}
            yield $id=>$client->getCommand('DeleteObjects',['Bucket'=>$bucket,'Delete'=>['Objects'=>$objects,'Quiet'=>false]]);
        }
    };
    $pool=new CommandPool($client,$commands(),[
        'concurrency'=>$concurrency,'preserve_iterator_keys'=>true,
        'fulfilled'=>function($result,$id)use($dir,&$selected,&$stopped,$started){
            $data=$selected[$id]+['at'=>gmdate('c'),'deleted'=>$result['Deleted']??[],'errors'=>$result['Errors']??[]];
            if($data['errors'] || count($data['deleted'])!==$data['count']){$stopped=true;saveJson($dir.'/errors/'.$id.'.json',$data);}
            else{saveJson($dir.'/receipts/'.$id.'.json',$data);if(file_exists($dir.'/errors/'.$id.'.json'))unlink($dir.'/errors/'.$id.'.json');echo json_encode(['batch'=>$id,'deleted'=>$data['count'],'bytes'=>$data['bytes'],'seconds'=>round(microtime(true)-$started,1)])."\n";}
            unset($selected[$id]);
        },
        'rejected'=>function($reason,$id)use($dir,&$stopped){$stopped=true;saveJson($dir.'/errors/'.$id.'.json',['error'=>(string)$reason,'at'=>gmdate('c')]);}
    ]);
    $pool->promise()->wait();
    $summary=['completed_batches'=>count(glob($dir.'/receipts/*.json')),'planned_batches'=>count($files),'errors'=>count(glob($dir.'/errors/*.json')),'at'=>gmdate('c')];
    saveJson($dir.'/deletion-summary.json',$summary);echo 'SUMMARY '.json_encode($summary)."\n";
    if($summary['errors'] || $summary['completed_batches']!==count($files))exit(1);
} elseif ($mode === 'verify') {
    $plan=readJson($dir.'/plan.json');$refs=references();$before=readJson(dirname($dir).'/protected-heads-before.json');
    $protected=$refs['paths']+readJson($dir.'/references.json')['paths'];
    if(file_exists($dir.'/references-at-delete.json'))$protected+=readJson($dir.'/references-at-delete.json')['paths'];
    $p=['Bucket'=>$bucket,'Prefix'=>'imported/','MaxKeys'=>1000];$seen=[];$current=[];$remaining=[];$unexpected=[];$total=0;$bytes=0;$complete=false;
    for($page=0;$page<20000;$page++){
        $r=$client->listObjectVersions($p);
        foreach($r['Versions']??[] as $v){
            $key=$v['Key'];$total++;$bytes+=(int)$v['Size'];
            if($v['IsLatest'])$current[$key]=['etag'=>$v['ETag'],'size'=>(int)$v['Size'],'version'=>$v['VersionId']??null];
            $why=isset($protected[$key])?'referenced_or_previously_protected':($v['LastModified']->getTimestamp()>=strtotime($plan['cutoff'])?'recent':(!preg_match('~^imported/[a-f0-9-]{36}/[a-f0-9-]{36}/[a-f0-9]{31,64}\\.jpg$~',$key)?'unrecognized_path':'unexpected_old_unreferenced'));
            $remaining[$why]['count']=($remaining[$why]['count']??0)+1;$remaining[$why]['bytes']=($remaining[$why]['bytes']??0)+(int)$v['Size'];
            if($why==='unexpected_old_unreferenced' && count($unexpected)<20)$unexpected[]=$key;
        }
        if(!$r['IsTruncated']){$complete=true;break;}
        $p['KeyMarker']=$r['NextKeyMarker'];$p['VersionIdMarker']=$r['NextVersionIdMarker'];$token=$p['KeyMarker'].'|'.$p['VersionIdMarker'];
        if(isset($seen[$token]))throw new RuntimeException('Repeated verification marker');$seen[$token]=true;
    }
    if(!$complete)throw new RuntimeException('Incomplete verification');
    $changed=[];foreach($before['objects'] as $key=>$v){$actual=$current[$key]??null;$v['version']??='null';if($actual)$actual['version']??='null';if($actual!==$v)$changed[]=$key;}
    $missing=[];foreach($refs['paths'] as $key=>$_)if(!isset($current[$key]))$missing[]=$key;
    $out=['at'=>gmdate('c'),'remaining_versions'=>$total,'remaining_bytes'=>$bytes,'categories'=>$remaining,'current_references'=>count($refs['paths']),'missing_references'=>$missing,'changed_baseline_objects'=>$changed,'unexpected_examples'=>$unexpected];
    saveJson($dir.'/verification.json',$out);echo 'VERIFIED '.json_encode($out)."\n";
    if($missing || $changed || $unexpected)exit(1);
} else throw new RuntimeException('Unknown mode');
