<?php

declare(strict_types=1);

// Run inside the existing application: php /path/to/export-youtube-audience.php WORKSPACE ACCOUNT START END [all|subscribed|unsubscribed]
// No DB writes, token refresh, background dispatch, or provider state changes.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Support\YoutubeAudienceReport;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require_once getenv('YOUTUBE_AUDIENCE_REPORT_PATH') ?: dirname(__DIR__).'/production-overrides/YoutubeAudienceReport.php';

try {
    [$script, $workspace, $accountId, $start, $end] = array_pad($argv, 5, null);
    if (! ctype_digit((string) $workspace) || ! ctype_digit((string) $accountId) || ! $start || ! $end) {
        throw new InvalidArgumentException('Usage: export-youtube-audience.php WORKSPACE ACCOUNT START END [all|subscribed|unsubscribed]');
    }
    YoutubeAudienceReport::validateRange($start, $end);
    $account = Account::withoutGlobalScopes()->where('workspace_id', $workspace)->where('id', $accountId)->where('provider', 'youtube')->first();
    if (! $account || ! $account->isAuthorized()) {
        throw new InvalidArgumentException('Authorized YouTube account not found in this workspace.');
    }
    $token = (array) $account->access_token;
    if (($token['expires_in'] ?? 0) <= time() + 60) {
        echo json_encode(['status' => 'token_expired', 'accountId' => (int) $accountId, 'workspaceId' => (int) $workspace]), "\n";
        exit(2);
    }
    // The installed OAuth handler does not store scopes. Inspect them without exposing the token.
    $tokenInfo = Http::timeout(20)->get('https://oauth2.googleapis.com/tokeninfo', ['access_token' => $token['access_token']]);
    if (! $tokenInfo->successful()) {
        throw new RuntimeException('Cannot verify YouTube authorization.');
    }
    $report = YoutubeAudienceReport::collect((int) $account->id, (int) $account->workspace_id, $account->provider_id, $start, $end, (string) $tokenInfo->json('scope', ''), static function (string $url, array $query) use ($token): array {
        $response = Http::withToken($token['access_token'])->timeout(30)->get($url, $query);
        return ['status' => $response->status(), 'body' => $response->json() ?? []];
    }, $argv[5] ?? 'all');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
    exit(in_array($report['status'], ['available', 'no_reportable_data'], true) ? 0 : 2);
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(64);
} catch (Throwable) {
    // Exception text from HTTP/database clients may contain secrets; keep this diagnostic fixed.
    fwrite(STDERR, "YouTube audience export failed. No credentials or upstream error text were emitted.\n");
    exit(1);
}
