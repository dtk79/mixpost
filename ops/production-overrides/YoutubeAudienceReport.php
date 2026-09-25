<?php

declare(strict_types=1);

namespace Inovector\Mixpost\Support;

use DateTimeImmutable;
use InvalidArgumentException;
use Throwable;

/** Read-only YouTube Analytics acquisition. Transport and credentials stay with the caller. */
final class YoutubeAudienceReport
{
    public const SCOPE = 'https://www.googleapis.com/auth/yt-analytics.readonly';
    private const MONETARY_SCOPE = 'https://www.googleapis.com/auth/yt-analytics-monetary.readonly';

    /**
     * $get receives a fixed Google endpoint plus query and returns {status:int,body:array}.
     * It must attach authorization server-side, enforce a timeout, and never log credentials.
     */
    public static function collect(int $accountId, int $workspaceId, string $channelId, string $start, string $end, string $scope, callable $get, string $subscription = 'all'): array
    {
        self::validateRange($start, $end);
        if ($accountId < 1 || $workspaceId < 1 || ! preg_match('/^UC[A-Za-z0-9_-]{22}$/D', $channelId)) {
            throw new InvalidArgumentException('A valid account, workspace and channel are required.');
        }
        if (! in_array($subscription, ['all', 'subscribed', 'unsubscribed'], true)) {
            throw new InvalidArgumentException('Invalid subscription filter.');
        }
        $result = [
            'schemaVersion' => 1,
            'provider' => 'youtube',
            'accountId' => $accountId,
            'workspaceId' => $workspaceId,
            'channelId' => $channelId,
            'audienceType' => 'viewers',
            'subscription' => $subscription,
            'period' => ['start' => $start, 'end' => $end, 'timeZone' => 'America/Los_Angeles'],
            'fetchedAt' => gmdate('c'),
            // Google may lag the requested end; acquisition time is not a completeness watermark.
            'dataThrough' => null,
            'reports' => [],
        ];
        $scopes = preg_split('/\s+/', trim($scope));
        if (! in_array(self::SCOPE, $scopes, true) && ! in_array(self::MONETARY_SCOPE, $scopes, true)) {
            return $result + ['status' => 'authorization_required', 'requiredScope' => self::SCOPE];
        }
        $base = ['ids' => 'channel=='.$channelId, 'startDate' => $start, 'endDate' => $end];
        if ($subscription !== 'all') {
            $base['filters'] = 'subscribedStatus=='.strtoupper($subscription);
        }
        foreach (['age' => ['ageGroup', 'viewerPercentage'], 'gender' => ['gender', 'viewerPercentage'], 'country' => ['country', 'views']] as $key => [$dimension, $metric]) {
            $query = $base + ['dimensions' => $dimension, 'metrics' => $metric];
            $result['reports'][$key] = self::report($get, $query, $dimension, $metric);
        }
        $states = array_column($result['reports'], 'status');
        $result['status'] = count(array_unique($states)) === 1 ? $states[0] : 'partial';

        return $result;
    }

    public static function validateRange(string $start, string $end): void
    {
        foreach ([$start, $end] as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (! $parsed || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('Dates must be valid YYYY-MM-DD values.');
            }
        }
        if ($start > $end) {
            throw new InvalidArgumentException('Start must not be after end.');
        }
    }

    private static function report(callable $get, array $query, string $dimension, string $metric): array
    {
        $base = [
            'dimension' => $dimension,
            'metric' => $metric,
            'unit' => $metric === 'views' ? 'views' : 'percent',
            'population' => $metric === 'views' ? 'video_views' : 'logged_in_viewers',
            'buckets' => [],
        ];
        try {
            // Complete country table, not top-N shares. More than 500 countries is invalid.
            $response = $get('https://youtubeanalytics.googleapis.com/v2/reports', $query + ['maxResults' => 500]);
        } catch (Throwable) {
            return $base + ['status' => 'unavailable', 'reason' => 'transport_error'];
        }
        if (! is_array($response) || ! is_array($response['body'] ?? null)) {
            return $base + ['status' => 'unavailable', 'reason' => 'invalid_response'];
        }
        $status = $response['status'] ?? 0;
        if ($status !== 200) {
            // Never expose upstream messages: they can include query or credential details.
            $reason = $response['body']['error']['errors'][0]['reason'] ?? '';
            $detail = $response['body']['error']['details'][0]['reason'] ?? '';
            if ($status === 401 || $reason === 'insufficientPermissions' || $detail === 'ACCESS_TOKEN_SCOPE_INSUFFICIENT') {
                return $base + ['status' => 'authorization_required', 'reason' => 'analytics_access_required'];
            }
            if ($reason === 'accessNotConfigured' || $detail === 'SERVICE_DISABLED') {
                return $base + ['status' => 'unavailable', 'reason' => 'api_not_enabled'];
            }
            return $base + ['status' => 'unavailable', 'reason' => $status === 403 ? 'access_denied' : 'provider_error'];
        }
        $body = $response['body'] ?? [];
        if (! is_array($body) || ! isset($body['columnHeaders']) || ! is_array($body['columnHeaders'])) {
            return $base + ['status' => 'unavailable', 'reason' => 'invalid_response'];
        }
        $headers = array_column($body['columnHeaders'], 'name');
        $dimensionIndex = array_search($dimension, $headers, true);
        $metricIndex = array_search($metric, $headers, true);
        if ($dimensionIndex === false || $metricIndex === false) {
            return $base + ['status' => 'unavailable', 'reason' => 'invalid_response'];
        }
        $rows = $body['rows'] ?? [];
        if (! is_array($rows) || count($rows) >= 500) {
            return $base + ['status' => 'unavailable', 'reason' => 'invalid_response'];
        }
        if (! $rows) {
            // The API cannot distinguish privacy suppression from no reportable activity.
            return $base + ['status' => 'no_reportable_data', 'reason' => 'empty_or_privacy_limited'];
        }
        $buckets = [];
        $seen = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                return $base + ['status' => 'unavailable', 'reason' => 'invalid_response'];
            }
            $label = $row[$dimensionIndex] ?? null;
            $value = $row[$metricIndex] ?? null;
            if (! is_string($label) || ! self::validLabel($dimension, $label) || isset($seen[$label]) || ! is_numeric($value) || ! is_finite((float) $value) || (float) $value < 0 || ($metric === 'viewerPercentage' && (float) $value > 100) || ($metric === 'views' && floor((float) $value) !== (float) $value)) {
                return $base + ['status' => 'unavailable', 'reason' => 'invalid_response'];
            }
            $seen[$label] = true;
            $buckets[] = ['key' => $label, 'value' => $metric === 'views' ? (int) $value : (float) $value];
        }
        $sum = array_sum(array_column($buckets, 'value'));
        if ($metric === 'viewerPercentage' && $sum > 100.1) {
            return $base + ['status' => 'unavailable', 'reason' => 'invalid_response'];
        }
        $base['buckets'] = $buckets;
        // Never derive people counts from subscriber totals or normalize withheld percentages.
        return $base + ['status' => 'available', 'reportedTotal' => $sum, 'denominator' => $metric === 'views' ? 'reported_country_views' : 'provider_percentage'];
    }

    private static function validLabel(string $dimension, string $label): bool
    {
        return match ($dimension) {
            'ageGroup' => in_array($label, ['age13-17', 'age18-24', 'age25-34', 'age35-44', 'age45-54', 'age55-64', 'age65-'], true),
            'gender' => in_array($label, ['female', 'male', 'user_specified'], true),
            'country' => (bool) preg_match('/^[A-Z]{2}$/D', $label),
            default => false,
        };
    }
}
