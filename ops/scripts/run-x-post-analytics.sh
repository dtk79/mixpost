#!/usr/bin/env bash
set -euo pipefail

MODE="${1:-}"

if [[ "$MODE" != "active" && "$MODE" != "inactive" && "$MODE" != "all" ]]; then
    echo "Usage: $0 active|inactive|all" >&2
    exit 64
fi

HOST="${MIXPOST_HOST:-mixpost-hetzner}"
CONTAINER="${MIXPOST_CONTAINER:-mixpost-mixpost-1}"
DRY_RUN="${DRY_RUN:-0}"

ssh "$HOST" "docker exec -e X_ANALYTICS_MODE=$MODE -e DRY_RUN=$DRY_RUN -i -w /var/www/html $CONTAINER php" <<'PHP'
<?php

require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$mode = getenv('X_ANALYTICS_MODE') ?: 'all';
$dryRun = in_array(strtolower((string) getenv('DRY_RUN')), ['1', 'true', 'yes'], true);

if (! in_array($mode, ['active', 'inactive', 'all'], true)) {
    fwrite(STDERR, "Invalid mode: {$mode}\n");
    exit(64);
}

function isActiveTwitterAccount(Inovector\Mixpost\Models\Account $account): bool
{
    $recentPostCount = $account->posts()
        ->whereNotNull('published_at')
        ->where('published_at', '>=', Illuminate\Support\Carbon::now('UTC')->subDays(30))
        ->count();

    if ($recentPostCount >= 3) {
        return true;
    }

    return $account->posts()
        ->whereNotNull('published_at')
        ->where('published_at', '>=', Illuminate\Support\Carbon::now('UTC')->subDays(14))
        ->exists();
}

$selected = [];

Inovector\Mixpost\Models\Workspace::query()
    ->select(['id', 'name'])
    ->each(function (Inovector\Mixpost\Models\Workspace $workspace) use ($mode, $dryRun, &$selected): void {
        if (! $workspace->valid()) {
            return;
        }

        $workspace->execute(function () use ($workspace, $mode, $dryRun, &$selected): void {
            Inovector\Mixpost\Models\Account::query()
                ->where('provider', 'twitter')
                ->get()
                ->filter(fn (Inovector\Mixpost\Models\Account $account): bool => $account->isAuthorized() && $account->isServiceActive())
                ->filter(function (Inovector\Mixpost\Models\Account $account) use ($mode): bool {
                    if ($mode === 'all') {
                        return true;
                    }

                    return isActiveTwitterAccount($account) === ($mode === 'active');
                })
                ->each(function (Inovector\Mixpost\Models\Account $account) use ($workspace, $dryRun, &$selected): void {
                    $selected[] = [
                        'workspace' => $workspace->name,
                        'account_id' => $account->id,
                        'username' => $account->username,
                    ];

                    if (! $dryRun) {
                        Inovector\Mixpost\SocialProviders\Twitter\Jobs\ImportTwitterPostsJob::dispatch($account);
                    }
                });
        });
    });

foreach ($selected as $item) {
    echo sprintf(
        "%s\taccount_id=%s\t@%s\n",
        $item['workspace'],
        $item['account_id'],
        $item['username'] ?: 'unknown'
    );
}

$action = $dryRun ? 'Would dispatch' : 'Dispatched';
echo "{$action} ImportTwitterPostsJob for ".count($selected)." {$mode} X account(s).\n";
PHP
