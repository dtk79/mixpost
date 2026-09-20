<?php

namespace Inovector\Mixpost\Support;

final class PostFailureExplanation
{
    private const FALLBACK = 'Publishing failed without a detailed provider response. Review the publishing job logs before retrying.';

    private const MAX_MESSAGES = 5;

    private const MAX_MESSAGE_LENGTH = 500;

    public static function from(mixed $errors): string
    {
        $messages = self::collectMessages($errors);
        $unique = [];

        foreach ($messages as $message) {
            $message = self::sanitize($message);

            if ($message === '') {
                continue;
            }

            $key = strtolower($message);

            if (! array_key_exists($key, $unique)) {
                $unique[$key] = $message;
            }

            if (count($unique) >= self::MAX_MESSAGES) {
                break;
            }
        }

        return $unique ? implode('; ', array_values($unique)) : self::FALLBACK;
    }

    private static function collectMessages(mixed $value, ?string $key = null): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return self::collectMessages($decoded, $key);
            }

            return [self::humanize($value, $key)];
        }

        if (is_int($value) || is_float($value)) {
            $prefix = in_array($key, ['code', 'error_code', 'status_code'], true) ? 'Code: ' : '';

            return [$prefix.$value];
        }

        if (is_bool($value)) {
            return [$value ? 'True' : 'False'];
        }

        if (! is_array($value)) {
            return [];
        }

        if (array_is_list($value)) {
            return array_merge(...array_map(
                static fn (mixed $item): array => self::collectMessages($item),
                $value,
            ));
        }

        $priorityKeys = [
            'message',
            'error_description',
            'detail',
            'reason',
            'title',
            'error',
            'errors',
            'code',
            'error_code',
            'status_code',
        ];
        $messages = [];

        foreach ($priorityKeys as $priorityKey) {
            if (array_key_exists($priorityKey, $value)) {
                $messages = array_merge($messages, self::collectMessages($value[$priorityKey], $priorityKey));
            }
        }

        if ($messages) {
            return $messages;
        }

        foreach ($value as $nestedKey => $nestedValue) {
            $messages = array_merge($messages, self::collectMessages(
                $nestedValue,
                is_string($nestedKey) ? $nestedKey : null,
            ));
        }

        return $messages;
    }

    private static function humanize(string $message, ?string $key): string
    {
        $message = trim($message);

        return match ($message) {
            'service_disabled' => 'The social service is disabled in Mixpost.',
            'access_token_expired' => 'The account connection expired and needs to be reconnected.',
            default => self::humanizeMachineValue($message, $key),
        };
    }

    private static function humanizeMachineValue(string $message, ?string $key): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $message) !== 1) {
            return $message;
        }

        if (in_array($key, ['code', 'error_code', 'status_code'], true)) {
            return 'Code: '.$message;
        }

        $message = str_replace(['_', '-'], ' ', $message);
        $message = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $message) ?? $message;

        return ucfirst($message);
    }

    private static function sanitize(string $message): string
    {
        $message = preg_replace(
            [
                '/((?:access_token|refresh_token|client_secret|app_secret|authorization)[=:]\s*)[^&\s,\"]+/i',
                '/([?&](?:access_token|refresh_token|client_secret|app_secret)=)[^&\s]+/i',
                '/(Bearer\s+)[A-Za-z0-9._~+\/-]+/i',
            ],
            '$1[REDACTED]',
            trim($message),
        ) ?? '';

        if (strlen($message) > self::MAX_MESSAGE_LENGTH) {
            return substr($message, 0, self::MAX_MESSAGE_LENGTH - 3).'...';
        }

        return $message;
    }
}
