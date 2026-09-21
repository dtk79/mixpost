<?php

require getenv('MIXPOST_VENDOR_AUTOLOAD') ?: '/var/www/html/vendor/autoload.php';
require getenv('TWITTER_RESOURCES_PATH') ?: dirname(__DIR__).'/ManagesTwitterResources.php';

$provider = new class {
    use Inovector\Mixpost\SocialProviders\Twitter\Concerns\ManagesResources;

    public function mediaUploadErrors(mixed $result): array
    {
        return $this->twitterMediaUploadErrors($result);
    }

    public function shouldRetryChunkedUpload(mixed $result, int $httpCode): bool
    {
        return $this->twitterChunkedUploadShouldRetry($result, $httpCode);
    }
};

function checkTwitterResourceCondition(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

checkTwitterResourceCondition(
    $provider->mediaUploadErrors((object) [
        'errors' => [(object) ['code' => 324, 'message' => 'Unsupported media type']],
    ]) === ['X media upload error 324: Unsupported media type'],
    'Legacy X upload errors must retain their code and message'
);

checkTwitterResourceCondition(
    $provider->mediaUploadErrors((object) [
        'title' => 'Invalid Request',
        'detail' => 'One or more upload segments failed',
    ]) === ['X media upload error: Invalid Request: One or more upload segments failed'],
    'Structured X errors must retain their title and detail'
);

checkTwitterResourceCondition(
    $provider->mediaUploadErrors((object) ['message' => 'Upload timed out'])
        === ['X media upload error: Upload timed out'],
    'Top-level X error messages must be retained'
);

checkTwitterResourceCondition(
    $provider->mediaUploadErrors((object) []) === ['media_upload_missing_id'],
    'Unknown response shapes must keep the existing diagnostic fallback'
);

checkTwitterResourceCondition(
    $provider->shouldRetryChunkedUpload((object) [
        'errors' => [(object) ['message' => 'Segments do not add up to provided total file size.']],
    ], 400),
    'Incomplete chunk sessions must be retried'
);

checkTwitterResourceCondition(
    $provider->shouldRetryChunkedUpload((object) ['message' => 'Temporary provider failure'], 503),
    'Transient provider failures must be retried'
);

checkTwitterResourceCondition(
    ! $provider->shouldRetryChunkedUpload((object) [
        'errors' => [(object) ['message' => 'Unsupported media type']],
    ], 400),
    'Permanent provider errors must not be retried'
);

echo "Twitter media upload diagnostic and retry tests passed (7 cases)\n";
