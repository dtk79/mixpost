<?php

// Runs in a disposable Pro container with no production mounts or environment.
// Only Composer autoload is loaded: no application boot, database, queue, or remote storage.
require '/var/www/html/vendor/autoload.php';
require getenv('CHUNKED_UPLOAD_SOURCE_PATH');

use Illuminate\Container\Container;
use Illuminate\Contracts\Filesystem\Filesystem as FilesystemContract;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\ValidationException;
use Inovector\Mixpost\Exceptions\ChunkedUploadSessionNotFound;
use Inovector\Mixpost\Support\ChunkedUpload;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

function check(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function rejects(callable $operation, string $type): void
{
    try {
        $operation();
    } catch (Throwable $error) {
        check($error instanceof $type, 'Unexpected exception type: '.get_class($error));
        return;
    }
    throw new RuntimeException('Expected exception: '.$type);
}

class StorageDouble
{
    public array $parts = [];
    public array $writes = [];
    public array $completions = [];
    public int $listCalls = 0;
    public bool $loseAcknowledgment = false;
    public bool $failListing = false;
    public bool $paginate = false;
    public bool $expired = false;
    public string $root;
    public ?string $lockPath = null;

    public function uploadPart(array $request): array
    {
        if ($this->expired) {
            throw new Aws\S3\Exception\S3Exception('Expired upload', new Aws\Command('UploadPart'), ['code' => 'NoSuchUpload']);
        }
        if ($this->lockPath) {
            $handle = fopen($this->lockPath, 'c');
            check(! flock($handle, LOCK_EX | LOCK_NB), 'A remote write must hold the session lock');
            fclose($handle);
        }
        $bytes = stream_get_contents($request['Body']);
        $number = $request['PartNumber'];
        $this->writes[] = $number;
        $this->parts[$number] = ['PartNumber' => $number, 'Size' => strlen($bytes), 'ETag' => '"'.md5($bytes).'"'];
        if ($this->loseAcknowledgment) {
            $this->loseAcknowledgment = false;
            throw new RuntimeException('Simulated timeout after storage accepted part');
        }
        return ['ETag' => $this->parts[$number]['ETag']];
    }

    public function listParts(array $request): array
    {
        $this->listCalls++;
        if ($this->failListing) {
            throw new RuntimeException('Simulated storage listing timeout');
        }
        $parts = array_values(array_filter($this->parts, fn ($part) => $part['PartNumber'] > $request['PartNumberMarker']));
        usort($parts, fn ($a, $b) => $a['PartNumber'] <=> $b['PartNumber']);
        $truncated = $this->paginate && count($parts) > 1;
        if ($truncated) {
            $parts = array_slice($parts, 0, 1);
        }
        return ['Parts' => $parts, 'IsTruncated' => $truncated, 'NextPartNumberMarker' => $parts ? end($parts)['PartNumber'] : 0];
    }

    public function completeMultipartUpload(array $request): void
    {
        $this->completions[] = $request;
        $path = $this->root.'/'.$request['Key'];
        @mkdir(dirname($path), 0700, true);
        file_put_contents($path, 'completed');
    }

    public function abortMultipartUpload(array $request): void
    {
        $this->parts = [];
    }
}

class TestFilesystem extends FilesystemAdapter
{
    public StorageDouble $client;
    public function getClient(): StorageDouble { return $this->client; }
}

class UploadHarness extends ChunkedUpload
{
    public string $root;
    public array $owner = ['workspace_id' => 12, 'user_id' => 15];
    public TestFilesystem $storage;
    public ?string $readSignal = null;
    public function __construct() { $this->disk = 'test'; }
    protected function currentOwner(): array { return $this->owner; }
    protected function getTempDirectory(string $uuid): string { return $this->root.'/sessions/'.$uuid; }
    protected function filesystem(): FilesystemContract { return $this->storage; }
    public function getSessionData(string $uuid): array
    {
        $session = parent::getSessionData($uuid);
        if ($this->readSignal) {
            file_put_contents($this->readSignal, 'read');
            $this->readSignal = null;
        }
        return $session;
    }
    public function seed(string $uuid, array $changes = []): void
    {
        $this->saveSessionData($uuid, array_replace([
            ...$this->owner, 'filename' => 'test.mp4', 'mime_type' => 'video/mp4',
            'total_size' => 12, 'total_chunks' => 3, 'chunk_size' => 4, 'disk' => 'test',
            'is_s3' => true, 's3_upload_id' => 'fake-upload', 's3_key' => 'chunked-uploads/'.$uuid.'/test.mp4',
            's3_parts' => [],
        ], $changes));
    }
    public function lockPath(string $uuid): string { return $this->getTempDirectory($uuid).'/session.lock'; }
    public function sessionPath(string $uuid): string { return $this->getTempDirectory($uuid).'/session.json'; }
}

$container = new Container;
Facade::setFacadeApplication($container);
File::swap(new Illuminate\Filesystem\Filesystem);
$container->instance('validator', new Factory(new Translator(new ArrayLoader, 'en'), $container));
$root = sys_get_temp_dir().'/mixpost-recovery-test-'.bin2hex(random_bytes(8));
mkdir($root, 0700, true);
$adapter = new LocalFilesystemAdapter($root.'/objects');
$filesystem = new TestFilesystem(new Filesystem($adapter), $adapter, ['bucket' => 'test']);
$client = new StorageDouble;
$client->root = $root.'/objects';
$filesystem->client = $client;
Storage::swap(new class($filesystem) {
    public function __construct(private TestFilesystem $filesystem) {}
    public function disk(string $disk): TestFilesystem { return $this->filesystem; }
});
$upload = new UploadHarness;
$upload->root = $root;
$upload->storage = $filesystem;

function chunk(string $bytes = 'abcd'): UploadedFile
{
    global $root;
    $path = tempnam($root, 'chunk-');
    file_put_contents($path, $bytes);
    return new UploadedFile($path, 'chunk', 'application/octet-stream', null, true);
}

function scenario(string $name, callable $operation): void
{
    global $client;
    $client->parts = $client->writes = $client->completions = [];
    $client->listCalls = 0;
    $client->failListing = $client->loseAcknowledgment = $client->paginate = $client->expired = false;
    $client->lockPath = null;
    $operation();
    fwrite(STDOUT, 'PASS '.$name."\n");
}

try {
    scenario('accepted part with lost acknowledgment is recovered without retransmission', function () use ($upload, $client): void {
        $upload->seed('lost-ack');
        $client->lockPath = $upload->lockPath('lost-ack');
        $client->loseAcknowledgment = true;
        rejects(fn () => $upload->uploadChunk('lost-ack', 0, chunk()), RuntimeException::class);
        check(count($upload->getSessionData('lost-ack')['s3_parts']) === 0, 'Lost acknowledgment must not be counted');
        $result = $upload->uploadChunk('lost-ack', 0, chunk());
        check($result['recovered'] === true && $client->writes === [1], 'Recovery must skip a verified remote part');
        $upload->uploadChunk('lost-ack', 0, chunk());
        check(count($upload->getSessionData('lost-ack')['s3_parts']) === 1, 'Repeated retry must not duplicate a part');
    });

    scenario('different contents or size are rejected without a remote write', function () use ($upload, $client): void {
        $upload->seed('changed');
        $upload->uploadChunk('changed', 0, chunk());
        rejects(fn () => $upload->uploadChunk('changed', 0, chunk('xxxx')), ValidationException::class);
        rejects(fn () => $upload->uploadChunk('changed', 1, chunk('short')), ValidationException::class);
        rejects(fn () => $upload->uploadChunk('changed', 3, chunk()), ValidationException::class);
        check($client->writes === [1], 'Invalid chunks must never reach storage');
    });

    scenario('remote parts with unverified content are safely overwritten', function () use ($upload, $client): void {
        $upload->seed('mismatch', ['s3_chunk_hashes' => [1 => md5('abcd')]]);
        $client->parts[1] = ['PartNumber' => 1, 'Size' => 4, 'ETag' => '"'.md5('xxxx').'"'];
        $upload->uploadChunk('mismatch', 0, chunk());
        check($client->writes === [1], 'Size alone must not be used to recover a part');
        $client->parts[1]['Size'] = 3;
        $upload->uploadChunk('mismatch', 0, chunk());
        check($client->writes === [1, 1], 'A wrong-sized remote part must not be recovered');
    });

    scenario('storage listing failure preserves the checkpoint for later retry', function () use ($upload, $client): void {
        $upload->seed('listing', ['s3_chunk_hashes' => [1 => md5('abcd')]]);
        $client->failListing = true;
        rejects(fn () => $upload->uploadChunk('listing', 0, chunk()), RuntimeException::class);
        check($client->writes === [], 'Do not write while remote state cannot be checked');
        check($upload->getSessionData('listing')['s3_chunk_hashes'][1] === md5('abcd'), 'Checkpoint must remain');
        $client->failListing = false;
        $upload->uploadChunk('listing', 0, chunk());
        check($client->writes === [1], 'A part absent from storage must be retransmitted');
    });

    scenario('pagination can recover a part after the first storage page', function () use ($upload, $client): void {
        $upload->seed('pages', ['s3_chunk_hashes' => [3 => md5('abcd')]]);
        foreach ([1, 2, 3] as $number) {
            $client->parts[$number] = ['PartNumber' => $number, 'Size' => 4, 'ETag' => '"'.md5('abcd').'"'];
        }
        $client->paginate = true;
        $result = $upload->uploadChunk('pages', 2, chunk());
        check($result['recovered'] && $client->writes === [] && $client->listCalls === 3, 'Read all storage pages');
    });

    scenario('partial uploads cannot be finalized; legacy duplicates are deduplicated', function () use ($upload, $client): void {
        $upload->seed('partial', ['s3_parts' => [['part_number' => 1, 'etag' => 'a']]]);
        rejects(fn () => $upload->complete('partial'), ValidationException::class);
        check($client->completions === [], 'No remote completion of a partial file');
        $upload->seed('full', ['s3_parts' => [
            ['part_number' => 3, 'etag' => 'c'], ['part_number' => 1, 'etag' => 'old'],
            ['part_number' => 2, 'etag' => 'b'], ['part_number' => 1, 'etag' => 'a'],
        ]]);
        $upload->complete('full');
        check($client->completions[0]['MultipartUpload']['Parts'] === [
            ['PartNumber' => 1, 'ETag' => 'a'], ['PartNumber' => 2, 'ETag' => 'b'], ['PartNumber' => 3, 'ETag' => 'c'],
        ], 'Complete exactly once per part, in order');
    });

    scenario('another user or workspace cannot read, recover, complete, or abort a session', function () use ($upload, $client): void {
        $upload->seed('owned');
        $originalOwner = $upload->owner;
        foreach ([['workspace_id' => 13, 'user_id' => 15], ['workspace_id' => 12, 'user_id' => 16]] as $owner) {
            $upload->owner = $owner;
            rejects(fn () => $upload->getSessionData('owned'), ChunkedUploadSessionNotFound::class);
            rejects(fn () => $upload->uploadChunk('owned', 0, chunk()), ChunkedUploadSessionNotFound::class);
            rejects(fn () => $upload->complete('owned'), ChunkedUploadSessionNotFound::class);
            rejects(fn () => $upload->abort('owned'), ChunkedUploadSessionNotFound::class);
        }
        $upload->owner = $originalOwner;
        check($client->writes === [] && $client->completions === [] && $client->listCalls === 0, 'No remote activity for another owner');
    });

    scenario('canceled sessions remain deleted and cannot be recreated by retry', function () use ($upload, $client): void {
        $upload->seed('cancel');
        $upload->uploadChunk('cancel', 0, chunk());
        $upload->abort('cancel');
        rejects(fn () => $upload->uploadChunk('cancel', 0, chunk()), ChunkedUploadSessionNotFound::class);
        check(! file_exists($upload->sessionPath('cancel')), 'Retry must not resurrect canceled session');
    });

    scenario('short final chunk uses the exact remaining size', function () use ($upload, $client): void {
        $upload->seed('tail', ['total_size' => 10]);
        rejects(fn () => $upload->uploadChunk('tail', 2, chunk()), ValidationException::class);
        $upload->uploadChunk('tail', 2, chunk('ab'));
        check($client->parts[3]['Size'] === 2, 'Accept the exact final chunk size');
    });

    scenario('an expired remote session is reported as validation failure, not a retryable server error', function () use ($upload, $client): void {
        $upload->seed('expired');
        $client->expired = true;
        rejects(fn () => $upload->uploadChunk('expired', 0, chunk()), ValidationException::class);
        check($client->writes === [], 'No writes to an expired session');
    });

    scenario('local disk upload and assembly still work with exact chunk sizes', function () use ($upload, $client): void {
        $upload->seed('local', ['is_s3' => false]);
        foreach ([0 => 'abcd', 1 => 'efgh', 2 => 'ijkl'] as $index => $bytes) {
            $upload->uploadChunk('local', $index, chunk($bytes));
        }
        check($upload->complete('local')->getContents() === 'abcdefghijkl', 'Local chunks must assemble in order');
        check($client->writes === [] && $client->completions === [], 'Local uploads must not contact remote storage');
    });

    check(function_exists('pcntl_fork'), 'The isolated runtime must support the concurrency test');
    scenario('a request waiting on a lock rechecks cancellation before writing', function () use ($upload, $root): void {
        $upload->seed('race');
        $handle = fopen($upload->lockPath('race'), 'c');
        flock($handle, LOCK_EX);
        $signal = $root.'/race-read';
        $pid = pcntl_fork();
        check($pid !== -1, 'Could not fork concurrency test');
        if ($pid === 0) {
            fclose($handle);
            $upload->readSignal = $signal;
            try {
                $upload->uploadChunk('race', 0, chunk());
                exit(2);
            } catch (ChunkedUploadSessionNotFound) {
                exit(0);
            } catch (Throwable) {
                exit(3);
            }
        }
        for ($attempt = 0; $attempt < 100 && ! file_exists($signal); $attempt++) {
            usleep(10000);
            clearstatcache(true, $signal);
        }
        check(file_exists($signal), 'The waiting request did not read its initial session');
        unlink($upload->sessionPath('race'));
        flock($handle, LOCK_UN);
        fclose($handle);
        pcntl_waitpid($pid, $status);
        check(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, 'Canceled session must be rechecked after the lock');
        check(! file_exists($upload->sessionPath('race')), 'Concurrent retry must not resurrect canceled session');
    });
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
