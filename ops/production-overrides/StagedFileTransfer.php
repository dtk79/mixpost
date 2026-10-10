<?php

namespace Inovector\Mixpost\Support;

use Aws\S3\Exception\S3Exception;
use RuntimeException;

/** One deterministic key, verified before any database pointer or local file changes. */
class StagedFileTransfer
{
    public function __construct(private object $client, private string $bucket) {}

    public function transfer(string $localPath, string $key, string $mimeType): void
    {
        if (! is_file($localPath) || ! is_readable($localPath)) {
            throw new RuntimeException('Staged file is unavailable');
        }
        $size = filesize($localPath);
        $digest = md5_file($localPath);
        if ($size === false || $digest === false) {
            throw new RuntimeException('Cannot read staged file');
        }
        $parameters = ['Bucket' => $this->bucket, 'Key' => $key,
            '@http' => ['connect_timeout' => 10, 'timeout' => 60], '@retries' => 0];
        if ($this->matches($parameters, $size, $digest)) {
            return; // Also reconciles an accepted PUT whose acknowledgment was lost.
        }
        $stream = fopen($localPath, 'rb');
        if ($stream === false) { throw new RuntimeException('Cannot open staged file'); }
        try {
            $this->client->putObject($parameters + [
                'Body' => $stream, 'ContentLength' => $size,
                'ContentMD5' => base64_encode(hex2bin($digest)),
                'ContentType' => $mimeType, 'ACL' => 'public-read',
            ]);
        } finally {
            fclose($stream);
        }
        if (! $this->matches($parameters, $size, $digest)) {
            throw new RuntimeException('Stored file verification failed');
        }
    }

    private function matches(array $parameters, int $size, string $digest): bool
    {
        try {
            $head = $this->client->headObject($parameters);
        } catch (S3Exception $error) {
            if ($error->getStatusCode() === 404) { return false; }
            throw $error; // Listing/acknowledgment failures cannot authorize a rewrite.
        }
        // Single PUTs are limited by our existing 500 MB policy, below S3's 5 GB limit.
        // HEL1's single-PUT ETag is the content MD5. Unknown ETags fail closed.
        return (int) ($head['ContentLength'] ?? -1) === $size
            && hash_equals($digest, trim((string) ($head['ETag'] ?? ''), '"'));
    }
}
