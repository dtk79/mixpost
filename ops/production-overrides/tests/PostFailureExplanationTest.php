<?php

$helperPath = getenv('POST_FAILURE_EXPLANATION_PATH') ?: dirname(__DIR__).'/PostFailureExplanation.php';

require $helperPath;

use Inovector\Mixpost\Support\PostFailureExplanation;

function expectExplanation(string $expected, mixed $errors, string $case): void
{
    $actual = PostFailureExplanation::from($errors);

    if ($expected !== $actual) {
        fwrite(STDERR, "Failed: {$case}\nExpected: {$expected}\nActual: {$actual}\n");
        exit(1);
    }
}

expectExplanation(
    'The social service is disabled in Mixpost.',
    '["service_disabled"]',
    'explains a disabled service',
);
expectExplanation(
    'The account connection expired and needs to be reconnected.',
    ['access_token_expired'],
    'explains an expired connection',
);
expectExplanation(
    'Internal Server Error; Code: 500',
    ['error' => 'InternalServerError', 'message' => 'Internal Server Error', 'code' => 500],
    'formats provider error objects',
);
expectExplanation(
    'Request failed with access_token=[REDACTED]&item=1',
    ['Request failed with access_token=secret-value&item=1'],
    'redacts secrets',
);
expectExplanation(
    'Publishing failed without a detailed provider response. Review the publishing job logs before retrying.',
    [],
    'uses a safe fallback',
);

echo "Post failure explanation tests passed (5 cases)\n";
