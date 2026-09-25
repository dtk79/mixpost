<?php

declare(strict_types=1);

// Mutating operator command: refreshes the existing token if needed and persists reports.
// Run only after migration and deliberate workspace opt-in; never from an Annex page request.
use Illuminate\Contracts\Console\Kernel;
use Inovector\Mixpost\Models\Workspace;
use Inovector\Mixpost\Support\YoutubeAudienceCollector;
use Inovector\Mixpost\Support\YoutubeAudienceSnapshot;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
try {
    [$script, $workspaceId, $accountId, $start, $end] = array_pad($argv, 5, null);
    if (! ctype_digit((string) $workspaceId) || ! ctype_digit((string) $accountId) || ($start === null) !== ($end === null)) {
        throw new InvalidArgumentException('Usage: collect-youtube-audience.php WORKSPACE ACCOUNT [START END]');
    }
    $workspace = Workspace::query()->where('id', $workspaceId)->first();
    if (! $workspace || ! $workspace->valid()) {
        throw new InvalidArgumentException('Valid workspace not found.');
    }
    $ranges = $start ? [['start' => $start, 'end' => $end]] : YoutubeAudienceSnapshot::windows();
    $success = true;
    $workspace->execute(function () use ($workspaceId, $accountId, $ranges, &$success): void {
        foreach ($ranges as $range) {
            $report = (new YoutubeAudienceCollector)->collect((int) $workspaceId, (int) $accountId, $range['start'], $range['end']);
            $success = $success && YoutubeAudienceSnapshot::successful($report);
            echo json_encode(['accountId' => (int) $accountId, 'workspaceId' => (int) $workspaceId, 'period' => $report['period'], 'status' => $report['status'], 'reason' => YoutubeAudienceSnapshot::reason($report)], JSON_THROW_ON_ERROR), "\n";
        }
    });
    exit($success ? 0 : 2);
} catch (InvalidArgumentException $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(64);
} catch (Throwable) {
    fwrite(STDERR, "YouTube snapshot collection failed. Check migration, workspace opt-in and account availability.\n");
    exit(1);
}
