<?php

// Exact installed Pro dependencies, no application boot, database, queue, or network.
require '/var/www/html/vendor/autoload.php';

use Aws\Command;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Psr7\Response;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Support\LocalMediaStaging;
use Inovector\Mixpost\Support\StagedFileTransfer;

function assertLocal(bool $condition): void { if (! $condition) { throw new RuntimeException('Assertion failed'); } }
function failsLocal(callable $fn): void
{
    try { $fn(); } catch (RuntimeException $error) { return; }
    throw new LogicException('Expected transfer failure');
}
class StagingStorageDouble
{
    public ?string $bytes = null;
    public int $writes = 0;
    public bool $loseAck = false;
    public bool $failHead = false;
    public bool $wrongAck = false;
    public bool $wrongSize = false;
    public array $request = [];
    public function headObject(array $request): array
    {
        if ($this->failHead) { throw new RuntimeException('Storage stalled'); }
        if ($this->bytes === null) {
            throw new S3Exception('Missing', new Command('HeadObject'), ['response' => new Response(404)]);
        }
        return ['ContentLength' => strlen($this->bytes) + (int) $this->wrongSize,
            'ETag' => $this->wrongAck ? 'opaque' : '"'.md5($this->bytes).'"'];
    }
    public function putObject(array $request): array
    {
        $this->request = $request;
        $this->bytes = stream_get_contents($request['Body']);
        $this->writes++;
        if ($this->loseAck) { $this->loseAck = false; throw new RuntimeException('Lost write acknowledgment'); }
        return [];
    }
}
$path = tempnam('/tmp', 'staging-test-');
$bytes = str_repeat('staged-upload-', 1024);
file_put_contents($path, $bytes);
$run = function (string $name, callable $test): void { $test(); echo 'PASS '.$name.PHP_EOL; };
try {
    $run('verified exact bytes and checksum', function () use ($path, $bytes): void {
        $client = new StagingStorageDouble;
        (new StagedFileTransfer($client, 'fixture'))->transfer($path, 'fixture.bin', 'video/mp4');
        assertLocal($client->writes === 1 && $client->bytes === $bytes);
        assertLocal($client->request['ContentMD5'] === base64_encode(md5($bytes, true)));
        assertLocal(is_file($path));
    });
    $run('lost acknowledgment reconciles without another write', function () use ($path): void {
        $client = new StagingStorageDouble; $client->loseAck = true;
        $transfer = new StagedFileTransfer($client, 'fixture');
        failsLocal(fn () => $transfer->transfer($path, 'fixture.bin', 'video/mp4'));
        $transfer->transfer($path, 'fixture.bin', 'video/mp4');
        assertLocal($client->writes === 1 && is_file($path));
    });
    $run('existing matching object skips write', function () use ($path, $bytes): void {
        $client = new StagingStorageDouble; $client->bytes = $bytes;
        (new StagedFileTransfer($client, 'fixture'))->transfer($path, 'fixture.bin', 'video/mp4');
        assertLocal($client->writes === 0);
    });
    $run('different object replaced at same deterministic key', function () use ($path, $bytes): void {
        $client = new StagingStorageDouble; $client->bytes = 'wrong';
        (new StagedFileTransfer($client, 'fixture'))->transfer($path, 'fixture.bin', 'video/mp4');
        assertLocal($client->bytes === $bytes && $client->request['Key'] === 'fixture.bin');
    });
    $run('failed remote digest retains local bytes', function () use ($path): void {
        $client = new StagingStorageDouble; $client->wrongAck = true;
        failsLocal(fn () => (new StagedFileTransfer($client, 'fixture'))->transfer($path, 'fixture.bin', 'video/mp4'));
        assertLocal(is_file($path));
    });
    $run('failed remote size retains local bytes', function () use ($path): void {
        $client = new StagingStorageDouble; $client->wrongSize = true;
        failsLocal(fn () => (new StagedFileTransfer($client, 'fixture'))->transfer($path, 'fixture.bin', 'video/mp4'));
        assertLocal(is_file($path));
    });
    $run('listing timeout cannot authorize rewrite', function () use ($path): void {
        $client = new StagingStorageDouble; $client->failHead = true;
        failsLocal(fn () => (new StagedFileTransfer($client, 'fixture'))->transfer($path, 'fixture.bin', 'video/mp4'));
        assertLocal($client->writes === 0 && is_file($path));
    });
    $run('missing local file cannot touch storage', function () use ($path): void {
        $client = new StagingStorageDouble;
        failsLocal(fn () => (new StagedFileTransfer($client, 'fixture'))->transfer($path.'-missing', 'fixture.bin', 'video/mp4'));
        assertLocal($client->writes === 0);
    });
    $run('delivery requests bounded and SDK replay disabled', function () use ($path): void {
        $client = new StagingStorageDouble;
        (new StagedFileTransfer($client, 'fixture'))->transfer($path, 'fixture.bin', 'video/mp4');
        assertLocal($client->request['@http']['timeout'] === 60 && $client->request['@retries'] === 0);
    });
    $run('existing unstaged media remains ready', function (): void { assertLocal(LocalMediaStaging::ready(new Media)); });
    $run('pending and unknown states block publication', function (): void {
        foreach (['pending', 'unexpected', null] as $state) {
            $media = new Media(['data' => ['peachy_storage' => ['state' => $state]]]);
            assertLocal(! LocalMediaStaging::ready($media));
        }
    });
    $run('ready state permits publication', function (): void {
        assertLocal(LocalMediaStaging::ready(new Media(['data' => ['peachy_storage' => ['state' => 'ready']]])));
    });
    $run('cleanup survives a ready record after worker restart', function (): void {
        $media = new Media(['data' => ['peachy_storage' => ['state' => 'ready', 'local_files' => [['path' => 'retained']]]]]);
        assertLocal(LocalMediaStaging::ready($media) && LocalMediaStaging::needsWork($media));
    });
    $run('capacity accepts healthy admission', fn () => assertLocal(LocalMediaStaging::capacityAllows(12000000, 10 * 1024 ** 3, 0)));
    $run('free space reserve and assembly headroom enforced', fn () => assertLocal(! LocalMediaStaging::capacityAllows(500000000, 6 * 1024 ** 3, 0)));
    $run('pending backlog admission enforced', fn () => assertLocal(! LocalMediaStaging::capacityAllows(12000000, 50 * 1024 ** 3, 20 * 1024 ** 3)));
    $run('unavailable free space fails closed', fn () => assertLocal(! LocalMediaStaging::capacityAllows(1, false, 0)));
} finally { unlink($path); }
