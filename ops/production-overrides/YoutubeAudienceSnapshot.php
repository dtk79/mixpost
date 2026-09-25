<?php

declare(strict_types=1);

namespace Inovector\Mixpost\Support;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;

final class YoutubeAudienceSnapshot
{
    public const TABLE = 'mixpost_youtube_audience_reports';

    public static function windows(?DateTimeImmutable $now = null): array
    {
        $today = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('America/Los_Angeles'))->setTime(0, 0);
        $end = $today->format('Y-m-d');
        $ranges = [
            ['start' => $end, 'end' => $end],
            ['start' => $today->modify('monday this week')->format('Y-m-d'), 'end' => $end],
            ['start' => $today->format('Y-m-01'), 'end' => $end],
        ];
        // Reports can lag. Revisit closed days instead of permanently caching the first empty response.
        foreach ([1, 2, 3] as $days) {
            $day = $today->modify("-$days days")->format('Y-m-d');
            $ranges[] = ['start' => $day, 'end' => $day];
        }
        return array_values(array_unique($ranges, SORT_REGULAR));
    }

    public static function successful(array $report): bool
    {
        $reports = $report['reports'] ?? [];
        foreach (['age', 'gender', 'country'] as $key) {
            if (! in_array($reports[$key]['status'] ?? null, ['available', 'no_reportable_data'], true)) {
                return false;
            }
        }
        return true;
    }

    public static function reason(array $report): ?string
    {
        if (self::successful($report)) {
            return null;
        }
        if (in_array($report['status'] ?? '', ['authorization_required', 'token_expired'], true)) {
            return $report['status'];
        }
        foreach (['age', 'gender', 'country'] as $key) {
            $reason = $report['reports'][$key]['reason'] ?? null;
            if (in_array($reason, ['transport_error', 'analytics_access_required', 'api_not_enabled', 'access_denied', 'provider_error', 'invalid_response'], true)) {
                return $reason;
            }
        }
        return 'collection_failed';
    }

    public static function store(array $report): void
    {
        // Validate before querying and enforce account/channel ownership again at the persistence boundary.
        $fresh = self::record($report);
        DB::transaction(function () use ($report, $fresh): void {
            $account = DB::table('mixpost_accounts')->where('id', $fresh['account_id'])
                ->where('workspace_id', $fresh['workspace_id'])->where('provider', 'youtube')
                ->where('provider_id', $fresh['channel_id'])->lockForUpdate()->first();
            if (! $account) {
                throw new InvalidArgumentException('YouTube snapshot account identity changed.');
            }
            $key = array_intersect_key($fresh, array_flip(['workspace_id', 'account_id', 'start_date', 'end_date', 'subscription']));
            $previous = DB::table(self::TABLE)->where($key)->lockForUpdate()->first();
            if ($previous && $previous->last_attempt_at > $fresh['last_attempt_at']) {
                return;
            }
            $row = self::record($report, $previous ? (array) $previous : null);
            DB::table(self::TABLE)->upsert([$row], array_keys($key), ['channel_id', 'report_json', 'fetched_at', 'last_attempt_at', 'last_attempt_status', 'last_attempt_error']);
        });
    }

    /** Build a single atomic upsert, retaining a good exact-range snapshot across failed attempts. */
    public static function record(array $report, ?array $previous = null): array
    {
        YoutubeAudienceReport::validateRange($report['period']['start'] ?? '', $report['period']['end'] ?? '');
        if (($report['schemaVersion'] ?? null) !== 1 || ($report['provider'] ?? null) !== 'youtube'
            || ($report['audienceType'] ?? null) !== 'viewers' || ($report['subscription'] ?? null) !== 'all'
            || ! is_int($report['workspaceId'] ?? null) || $report['workspaceId'] < 1
            || ! is_int($report['accountId'] ?? null) || $report['accountId'] < 1
            || ! preg_match('/^UC[A-Za-z0-9_-]{22}$/D', $report['channelId'] ?? '')
            || ! in_array($report['status'] ?? null, ['available', 'no_reportable_data', 'partial', 'authorization_required', 'unavailable', 'token_expired'], true)) {
            throw new InvalidArgumentException('Invalid YouTube snapshot envelope.');
        }
        $at = new DateTimeImmutable($report['fetchedAt']);
        $identity = [
            'workspace_id' => $report['workspaceId'], 'account_id' => $report['accountId'],
            'start_date' => $report['period']['start'], 'end_date' => $report['period']['end'], 'subscription' => 'all',
        ];
        $old = $previous ? json_decode((string) $previous['report_json'], true) : null;
        // Never retain data from a reconnect to another channel or a different requested period.
        $same = $previous && ! array_diff_assoc($identity, $previous) && $previous['channel_id'] === $report['channelId'];
        $keep = $same && is_array($old) && self::successful($old) && ! self::successful($report);
        return $identity + [
            'channel_id' => $report['channelId'],
            'report_json' => $keep ? $previous['report_json'] : json_encode($report, JSON_THROW_ON_ERROR),
            'fetched_at' => $keep ? $previous['fetched_at'] : $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'last_attempt_at' => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
            'last_attempt_status' => $report['status'],
            'last_attempt_error' => self::reason($report),
        ];
    }
}
