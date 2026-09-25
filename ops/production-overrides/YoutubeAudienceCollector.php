<?php

declare(strict_types=1);

namespace Inovector\Mixpost\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inovector\Mixpost\Concerns\UsesSocialProviderManager;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Models\Account;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class YoutubeAudienceCollector
{
    use UsesSocialProviderManager;

    /** Empty by default. Invalid config fails closed; collection can never escape these workspaces. */
    public static function workspaceIds(?string $configured = null): array
    {
        $value = $configured ?? (string) env('MIXPOST_YOUTUBE_AUDIENCE_WORKSPACE_IDS', '');
        if (! preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/D', $value)) {
            return [];
        }
        $ids = array_map('intval', explode(',', $value));
        if (count($ids) > 100 || array_filter($ids, fn (int $id): bool => $id < 1)) {
            return [];
        }
        return array_values(array_unique($ids));
    }

    public function collect(int $workspaceId, int $accountId, string $start, string $end): array
    {
        YoutubeAudienceReport::validateRange($start, $end);
        if (! in_array($workspaceId, self::workspaceIds(), true) || (int) WorkspaceManager::current()?->id !== $workspaceId) {
            throw new InvalidArgumentException('YouTube collection workspace is not enabled or active.');
        }
        $today = now('America/Los_Angeles')->toDateString();
        if ($end > $today || $start < '2000-01-01' || (new \DateTimeImmutable($end))->diff(new \DateTimeImmutable($start))->days > 365) {
            throw new InvalidArgumentException('Requested YouTube period is outside supported bounds.');
        }
        if (! Schema::hasTable(YoutubeAudienceSnapshot::TABLE)) {
            throw new RuntimeException('YouTube audience migration is not installed.');
        }
        // Serializes all ranges for this account, protects refresh/persistence, and keeps request fanout bounded.
        return Cache::lock("mixpost:youtube-audience:$workspaceId:$accountId", 180)->block(5, function () use ($workspaceId, $accountId, $start, $end): array {
            $account = Account::query()->where('workspace_id', $workspaceId)->where('id', $accountId)->where('provider', 'youtube')->first();
            if (! $account || ! $account->isAuthorized() || ! $account->isServiceActive()) {
                throw new RuntimeException('YouTube account is unavailable in this workspace.');
            }
            $report = $this->fetch($account, $start, $end);
            YoutubeAudienceSnapshot::store($report);
            return $report;
        });
    }

    private function fetch(Account $account, string $start, string $end): array
    {
        $failure = [
            'schemaVersion' => 1, 'provider' => 'youtube', 'accountId' => (int) $account->id,
            'workspaceId' => (int) $account->workspace_id, 'channelId' => $account->provider_id,
            'audienceType' => 'viewers', 'subscription' => 'all',
            'period' => ['start' => $start, 'end' => $end, 'timeZone' => 'America/Los_Angeles'],
            'fetchedAt' => gmdate('c'), 'dataThrough' => null, 'reports' => [], 'status' => 'unavailable',
        ];
        try {
            $provider = $this->connectProvider($account);
            // Existing provider helper locks refresh, re-reads tokens, and persists rotation through the sole token writer.
            // Analytics authorization failure must never mark a publishing account unauthorized.
            if ($refresh = $provider->refreshTokenIfNeeded()) {
                return array_replace($failure, ['status' => $refresh->isUnauthorized() ? 'authorization_required' : 'unavailable']);
            }
            $account->refresh();
            $token = $account->access_token->toArray();
            if (($token['expires_in'] ?? 0) <= time() + 60) {
                return array_replace($failure, ['status' => 'token_expired']);
            }
            $info = Http::timeout(10)->connectTimeout(5)->get('https://oauth2.googleapis.com/tokeninfo', ['access_token' => $token['access_token']]);
            if (! $info->successful()) {
                return array_replace($failure, ['status' => $info->status() === 400 || $info->status() === 401 ? 'authorization_required' : 'unavailable']);
            }
            return YoutubeAudienceReport::collect((int) $account->id, (int) $account->workspace_id, $account->provider_id, $start, $end, (string) $info->json('scope', ''), static function (string $url, array $query) use ($token): array {
                $response = Http::withToken($token['access_token'])->timeout(15)->connectTimeout(5)->get($url, $query);
                return ['status' => $response->status(), 'body' => $response->json() ?? []];
            });
        } catch (Throwable) {
            // Never let token-bearing HTTP exception strings reach jobs, logs or persistence.
            return $failure;
        }
    }
}
