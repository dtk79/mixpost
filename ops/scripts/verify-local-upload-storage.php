<?php

// One synthetic 12 MiB object; no Media record, publishing, or user upload changes.
// Require the exact candidate's StagedFileTransfer source as argument one.
if (posix_geteuid() === 0) { fwrite(STDERR, "Run as www-data\n"); exit(1); }
set_exception_handler(function (Throwable $error): void { fwrite(STDERR, get_class($error)."\n"); exit(1); });
require '/var/www/html/vendor/autoload.php';
require $argv[1];
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$disk = Illuminate\Support\Facades\Storage::disk(Inovector\Mixpost\Util::config('disk'));
$client = $disk->getClient();
class LostPutAcknowledgmentProbe
{
    public int $writes = 0;
    public function __construct(private object $client) {}
    public function headObject(array $request): mixed { return $this->client->headObject($request); }
    public function putObject(array $request): mixed
    {
        $result = $this->client->putObject($request);
        $this->writes++;
        throw new RuntimeException('Injected acknowledgment loss');
    }
}
$key = 'ops-local-upload-checks/'.bin2hex(random_bytes(16)).'.bin';
$path = tempnam(sys_get_temp_dir(), 'ops-local-storage-');
$bytes = str_repeat('L', 12 * 1024 * 1024);
$probe = new LostPutAcknowledgmentProbe($client);
$transfer = new Inovector\Mixpost\Support\StagedFileTransfer($probe, $disk->getConfig()['bucket']);
$request = ['Bucket' => $disk->getConfig()['bucket'], 'Key' => $key, '@http' => ['connect_timeout' => 10, 'timeout' => 60], '@retries' => 0];
$started = hrtime(true);
$verified = false;
$cleaned = false;
try {
    file_put_contents($path, $bytes);
    try { $transfer->transfer($path, $key, 'application/octet-stream'); }
    catch (RuntimeException $error) { if ($error->getMessage() !== 'Injected acknowledgment loss') { throw $error; } }
    $transfer->transfer($path, $key, 'application/octet-stream');
    $read = (string) $client->getObject($request)['Body'];
    if ($probe->writes !== 1 || ! hash_equals(hash('sha256', $bytes), hash('sha256', $read))) {
        throw new RuntimeException('Stored fixture differs or was rewritten');
    }
    $verified = true;
} finally {
    try {
        $client->deleteObject($request);
        try { $client->headObject($request); }
        catch (Aws\S3\Exception\S3Exception $error) { $cleaned = $error->getStatusCode() === 404; }
    } finally { if (is_file($path)) { unlink($path); } }
}
echo json_encode(['passed' => $verified && $cleaned, 'bytesVerified' => strlen($bytes),
    'writes' => $probe->writes, 'lostAckRecovered' => true, 'cleanupVerified' => $cleaned,
    'mediaInserted' => false, 'totalMs' => round((hrtime(true) - $started) / 1e6, 2),
    'transferSourceSha256' => hash_file('sha256', $argv[1])]).PHP_EOL;
exit($verified && $cleaned ? 0 : 1);
