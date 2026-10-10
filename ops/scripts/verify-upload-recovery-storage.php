<?php

// Controlled live storage check: one 10 MiB fixture, no media/database insertion.
// It creates a temporary multipart object and always attempts to remove it.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, 'Storage verification failed: '.get_class($error)."\n");
    exit(1);
});

class AcknowledgmentLossClient
{
    public int $writes = 0;
    public bool $loseNextAcknowledgment = true;
    public function __construct(private Aws\S3\S3Client $client) {}
    public function uploadPart(array $request): mixed
    {
        $result = $this->client->uploadPart($request);
        $this->writes++;
        if ($this->loseNextAcknowledgment) {
            $this->loseNextAcknowledgment = false;
            throw new RuntimeException('Simulated lost acknowledgment after successful storage write');
        }
        return $result;
    }
    public function __call(string $name, array $arguments): mixed { return $this->client->$name(...$arguments); }
}

class ProbeFilesystem extends Illuminate\Filesystem\FilesystemAdapter
{
    public AcknowledgmentLossClient $client;
    public function getClient(): AcknowledgmentLossClient { return $this->client; }
}

class StorageRecoveryProbe extends Inovector\Mixpost\Support\ChunkedUpload
{
    public ?ProbeFilesystem $probeFilesystem = null;
    protected function currentOwner(): array { return ['workspace_id' => -1, 'user_id' => 'ops-upload-recovery-probe']; }
    protected function filesystem(): Illuminate\Contracts\Filesystem\Filesystem
    {
        if (! $this->probeFilesystem) {
            $disk = parent::filesystem();
            $this->probeFilesystem = new ProbeFilesystem($disk->getDriver(), $disk->getAdapter(), $disk->getConfig());
            $this->probeFilesystem->client = new AcknowledgmentLossClient($disk->getClient());
        }
        return $this->probeFilesystem;
    }
}

$upload = new StorageRecoveryProbe;
$uuid = null;
$objectKey = null;
$path = tempnam(sys_get_temp_dir(), 'mixpost-upload-probe-');
try {
    $bytes = str_repeat('U', 10 * 1024 * 1024);
    file_put_contents($path, $bytes);
    $file = new Illuminate\Http\UploadedFile($path, 'upload-recovery-probe.bin', 'application/octet-stream', null, true);
    $session = $upload->initiate('upload-recovery-probe.bin', 'application/octet-stream', strlen($bytes));
    $uuid = $session['upload_uuid'];
    $objectKey = $upload->getSessionData($uuid)['s3_key'];
    if ($session['total_chunks'] !== 1) {
        throw new RuntimeException('Unexpected verification chunk plan');
    }
    try {
        $upload->uploadChunk($uuid, 0, $file);
        throw new LogicException('The injected acknowledgment loss did not occur');
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'Simulated lost acknowledgment after successful storage write') {
            throw $error;
        }
    }
    $result = $upload->uploadChunk($uuid, 0, $file);
    if (! ($result['recovered'] ?? false) || $upload->probeFilesystem->client->writes !== 1) {
        throw new RuntimeException('Stored part was not recovered without retransmission');
    }
    $source = $upload->complete($uuid);
    if (hash('sha256', $source->getContents()) !== hash('sha256', $bytes)) {
        throw new RuntimeException('Completed storage fixture differs from the uploaded bytes');
    }
} finally {
    try {
        if ($uuid) {
            $upload->abort($uuid);
            if (is_dir(storage_path('mixpost-media/temp/chunked/'.$uuid))
                || ($objectKey && $upload->probeFilesystem->exists($objectKey))) {
                throw new RuntimeException('Verification session or object was not cleaned up');
            }
        }
    } finally {
        @unlink($path);
    }
}
fwrite(STDOUT, json_encode(['partRecovered' => true, 'remoteWrites' => 1, 'multipartCompleted' => true, 'bytesVerified' => strlen($bytes), 'mediaInserted' => false, 'cleanupVerified' => true])."\n");
