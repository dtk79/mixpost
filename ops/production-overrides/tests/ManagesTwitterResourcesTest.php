<?php

require getenv('MIXPOST_VENDOR_AUTOLOAD') ?: '/var/www/html/vendor/autoload.php';
require getenv('TWITTER_RESOURCES_PATH') ?: dirname(__DIR__).'/ManagesTwitterResources.php';

$provider = new class {
    use Inovector\Mixpost\SocialProviders\Twitter\Concerns\ManagesResources;

    public function mediaUploadErrors(mixed $result): array
    {
        return $this->twitterMediaUploadErrors($result);
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

echo "Twitter media upload diagnostic tests passed (4 cases)\n";
