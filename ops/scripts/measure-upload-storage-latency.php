<?php

// Explicit live diagnostic: 12 MiB of synthetic bytes, no Media insert or publish.
// Run once as the application user; this is not a health check or scheduled job.
if (posix_geteuid() === 0) {
    fwrite(STDERR, "Run with docker exec -u www-data; root masks upload permissions\n");
    exit(1);
}
set_exception_handler(function (Throwable $error): void {
    // SDK messages and traces can contain signed URLs, bucket names, or credentials.
    fwrite(STDERR, json_encode(['failed' => true, 'errorClass' => get_class($error)])."\n");
    exit(1);
});
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$diskName = Inovector\Mixpost\Util::config('disk');
$disk = Illuminate\Support\Facades\Storage::disk($diskName);
$client = $disk->getClient();
if (! $client instanceof Aws\S3\S3Client) {
    throw new RuntimeException('This diagnostic requires S3 storage');
}
$started = hrtime(true);
$client->getHandlerList()->appendInit(function (callable $handler) {
    return function ($command, $request = null) use ($handler) {
        $name = $command->getName();
        $start = hrtime(true);
        $attempts = [];
        $http = $command['@http'] ?? [];
        // Cap individual diagnostic attempts; preserve SDK retries and checksum behavior.
        // This applies to this CLI process only, not production upload requests.
        $http['connect_timeout'] = 10;
        $http['timeout'] = 60;
        $http['on_stats'] = function (GuzzleHttp\TransferStats $stats) use (&$attempts): void {
            $attempts[] = [
                'seconds' => $stats->getTransferTime(),
                'status' => $stats->getResponse()?->getStatusCode(),
                'curl' => array_intersect_key($stats->getHandlerStats(), array_flip([
                    'namelookup_time', 'connect_time', 'appconnect_time', 'starttransfer_time',
                    'total_time', 'size_upload', 'size_download',
                ])),
            ];
        };
        $command['@http'] = $http;
        $record = function ($result, $error = null) use ($name, $start, &$attempts) {
            $event = ['operation' => $name, 'ms' => round((hrtime(true) - $start) / 1e6, 2), 'attempts' => $attempts];
            if ($error) {
                $event['errorClass'] = get_class($error);
            }
            echo json_encode($event).PHP_EOL;
            flush();
            if ($error) {
                throw $error;
            }
            return $result;
        };
        return $handler($command, $request)->then(fn ($r) => $record($r), fn ($e) => $record(null, $e));
    };
}, 'ops-upload-storage-latency');

class StorageLatencyProbe extends Inovector\Mixpost\Support\ChunkedUpload
{
    protected function currentOwner(): array { return ['workspace_id' => -1, 'user_id' => 'ops-storage-latency-probe']; }
}
function measurePhase(string $name, callable $fn): mixed
{
    $start = hrtime(true);
    $result = $fn();
    echo json_encode(['phase' => $name, 'ms' => round((hrtime(true) - $start) / 1e6, 2)]).PHP_EOL;
    flush();
    return $result;
}

$upload = new StorageLatencyProbe;
$uuid = $key = $copyDirectory = null;
$temporaryFiles = [];
$failure = null;
$cleanupVerified = false;
$bytes = str_repeat('Z', 12 * 1024 * 1024);
echo json_encode(['startedAt' => gmdate('c'), 'bytes' => strlen($bytes), 'httpAttemptTimeoutSeconds' => 60, 'sdkRetriesOverridden' => false]).PHP_EOL;
try {
    $session = measurePhase('initiate', fn () => $upload->initiate('storage-latency-probe.bin', 'application/octet-stream', strlen($bytes)));
    $uuid = $session['upload_uuid'];
    $key = $upload->getSessionData($uuid)['s3_key'];
    $copyDirectory = 'ops-upload-latency/'.$uuid;
    for ($i = 0; $i < $session['total_chunks']; $i++) {
        $path = tempnam(sys_get_temp_dir(), 'mixpost-latency-');
        if ($path === false) {
            throw new RuntimeException('Cannot create fixture');
        }
        $temporaryFiles[] = $path;
        $part = substr($bytes, $i * $session['chunk_size'], $session['chunk_size']);
        if (file_put_contents($path, $part) !== strlen($part)) {
            throw new RuntimeException('Cannot write fixture');
        }
        $file = new Illuminate\Http\UploadedFile($path, 'part.bin', 'application/octet-stream', null, true);
        measurePhase('chunk-'.$i, fn () => $upload->uploadChunk($uuid, $i, $file));
    }
    $source = measurePhase('complete', fn () => $upload->complete($uuid));
    $copied = measurePhase('copy-to-final-path', fn () => $source->storeAs($diskName, $copyDirectory));
    if (! is_string($copied) || ! str_starts_with($copied, $copyDirectory.'/')) {
        throw new RuntimeException('Copy failed');
    }
    $read = measurePhase('read-back', fn () => $disk->get($copied));
    if (hash('sha256', $read) !== hash('sha256', $bytes)) {
        throw new RuntimeException('Fixture bytes differ');
    }
} catch (Throwable $error) {
    $failure = $error;
} finally {
    // Independent cleanup attempts: a failed copy/delete must not skip session cleanup.
    $cleanupErrors = [];
    foreach ([
        fn () => ! $copyDirectory || $disk->deleteDirectory($copyDirectory),
        function () use ($upload, $uuid): bool { if ($uuid) { $upload->abort($uuid); } return true; },
    ] as $clean) {
        try {
            if (! $clean()) { $cleanupErrors[] = 'CleanupReturnedFalse'; }
        } catch (Throwable $error) {
            $cleanupErrors[] = get_class($error);
        }
    }
    foreach ($temporaryFiles as $path) {
        if (is_file($path) && ! unlink($path)) { $cleanupErrors[] = 'LocalCleanupFailed'; }
    }
    try {
        $cleanupVerified = $uuid !== null && $key !== null && ! $cleanupErrors
            && ! $disk->exists($key) && ! $disk->directoryExists($copyDirectory)
            && ! is_dir(storage_path('mixpost-media/temp/chunked/'.$uuid));
    } catch (Throwable $error) {
        $cleanupErrors[] = get_class($error);
    }
}
echo json_encode([
    'complete' => $failure === null && $cleanupVerified,
    'bytes' => strlen($bytes), 'totalMs' => round((hrtime(true) - $started) / 1e6, 2),
    'cleanupVerified' => $cleanupVerified, 'cleanupErrors' => $cleanupErrors,
    'mediaInserted' => false, 'errorClass' => $failure ? get_class($failure) : null,
]).PHP_EOL;
exit($failure === null && $cleanupVerified ? 0 : 1);
