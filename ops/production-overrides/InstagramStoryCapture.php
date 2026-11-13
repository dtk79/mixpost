<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inovector\Mixpost\Concerns\UsesSocialProviderManager;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Workspace;
use Inovector\Mixpost\Support\ProviderRateLimit;
use Inovector\Mixpost\Util;
use RuntimeException;
use Throwable;

final class InstagramStoryCapture
{
    use UsesSocialProviderManager;

    public const TABLE = 'peachy_ig_story_media';

    public function captureAccount(Account $account, Workspace $workspace, float $deadline): array
    {
        $result = ['captured' => 0, 'skipped' => 0, 'errors' => 0, 'status' => 'ok'];
        if ((int) $account->workspace_id !== (int) $workspace->id) {
            throw new RuntimeException('workspace_mismatch');
        }
        $provider = $this->connectProvider($account);
        $after = null;
        $cursors = [];
        do {
            if (microtime(true) >= $deadline) {
                return array_replace($result, ['status' => 'time_budget_exhausted', 'errors' => $result['errors'] + 1]);
            }
            if (ProviderRateLimit::retryAfter($account->id, $account->provider)) {
                return array_replace($result, ['status' => 'rate_limited']);
            }
            // Same HTTP facade, current decrypted token, endpoint and API version
            // as Mixpost's getStories(). Rebuild pagination from opaque cursors;
            // never follow or persist paging.next (it can contain credentials).
            $response = Http::withToken($provider->getAccessToken()['access_token'])
                ->connectTimeout(10)->timeout(30)->withOptions(['allow_redirects' => false])
                ->get($provider->resolveApiDomain().'/'.rawurlencode($account->provider_id).'/stories',
                    array_filter(['fields' => 'id,media_type,media_url,thumbnail_url,permalink,timestamp',
                        'after' => $after], static fn ($value) => $value !== null));
            $body = $response->json();
            $parsed = $provider->buildResponse($response);
            $limited = $response->status() === 429 || $parsed->hasExceededRateLimit();
            if ($limited || $parsed->rateLimitAboutToBeExceeded()) {
                ProviderRateLimit::record($account->id, $account->provider,
                    max(60, $parsed->retryAfter(), (int) $response->header('Retry-After')),
                    $parsed->isAppLevel() || in_array((int) data_get($body, 'error.code'), [4, 613], true));
                // A successful page at the warning threshold can still be captured.
                if ($limited) {
                    return array_replace($result, ['status' => 'rate_limited']);
                }
            }
            if (! $response->successful() || $parsed->hasError() || isset($body['error'])) {
                $code = (int) data_get($body, 'error.code');
                $subcode = (int) data_get($body, 'error.error_subcode');
                $unsupported = $account->provider === 'instagram_standalone' && $code === 100
                    && str_contains(strtolower((string) data_get($body, 'error.message')), 'nonexisting field (stories)');
                if ($unsupported) {
                    $noticeKey = 'peachy:stories:unsupported:'.$account->id;
                    if (! Cache::has($noticeKey)) {
                        Cache::forever($noticeKey, true);
                        Log::notice('peachy.instagram_stories_edge_unavailable', ['account_id' => $account->id]);
                    }
                    return array_replace($result, ['status' => 'unsupported']);
                }
                return array_replace($result, ['errors' => $result['errors'] + 1,
                    'status' => in_array($code, [10, 200], true) ? 'permission_missing'
                        : ($parsed->isUnauthorized() || $code === 190 ? 'unauthorized' : 'graph_error'),
                    'graph_code' => $code, 'graph_subcode' => $subcode]);
            }
            if (! is_array($body) || ! isset($body['data']) || ! is_array($body['data'])) {
                throw new RuntimeException('invalid_stories_response');
            }
            foreach ($body['data'] as $story) {
                if (microtime(true) >= $deadline) {
                    return array_replace($result, ['status' => 'time_budget_exhausted', 'errors' => $result['errors'] + 1]);
                }
                try {
                    foreach ($this->captureStory($account, $workspace, $story) as $key => $count) {
                        $result[$key] += $count;
                    }
                } catch (Throwable $error) {
                    $result['errors']++;
                    Log::warning('peachy.instagram_story_failed', ['account_id' => $account->id,
                        'exception_class' => $error::class]);
                }
            }
            if (empty($body['paging']['next'])) {
                break;
            }
            $after = $body['paging']['cursors']['after'] ?? null;
            if (! is_string($after) || $after === '' || isset($cursors[$after])) {
                throw new RuntimeException('invalid_stories_pagination');
            }
            $cursors[$after] = true;
        } while (true);
        return $result;
    }

    private function captureStory(Account $account, Workspace $workspace, array $story): array
    {
        $id = (string) ($story['id'] ?? '');
        $type = $story['media_type'] ?? '';
        if (! preg_match('/^\d{1,64}$/D', $id) || ! in_array($type, ['IMAGE', 'VIDEO'], true)
            || empty($story['timestamp'])) {
            throw new RuntimeException('invalid_story_metadata');
        }
        $posted = Carbon::parse($story['timestamp'], 'UTC')->utc();
        if ($posted->lte(now('UTC')->subDay())) {
            return ['skipped' => 1];
        }
        $query = DB::table(self::TABLE)->where('account_id', $account->id)->where('provider_post_id', $id);
        $row = $query->first();
        $video = $type === 'VIDEO' && config('peachy-stories.download_videos', true);
        if ($row && $row->image_path && (! $video || $row->video_path) && ! $row->error) {
            return ['skipped' => 1];
        }
        if (! $row) {
            DB::table(self::TABLE)->insert([
                'workspace_id' => $workspace->id, 'account_id' => $account->id,
                'provider_post_id' => $id, 'media_type' => $type,
                'permalink' => $story['permalink'] ?? null, 'posted_at' => $posted->toDateTimeString(),
                'disk' => Util::config('disk'), 'created_at' => now('UTC'), 'updated_at' => now('UTC'),
            ]);
            $row = $query->first();
        }
        if ((int) $row->workspace_id !== (int) $workspace->id) {
            throw new RuntimeException('stored_workspace_mismatch');
        }
        foreach ([$workspace->uuid, $account->uuid] as $uuid) {
            if (! preg_match('/^[A-Za-z0-9-]+$/D', $uuid)) {
                throw new RuntimeException('invalid_storage_scope');
            }
        }
        $prefix = "peachy/stories/{$workspace->uuid}/{$account->uuid}/$id";
        $errors = [];
        $saved = false;
        $mediaUrl = $story['media_url'] ?? null;
        if (! $mediaUrl) {
            $errors[] = 'missing_media_url';
        }
        $targets = ['image' => $type === 'IMAGE' ? $mediaUrl : ($story['thumbnail_url'] ?? null)];
        if ($video) {
            $targets['video'] = $mediaUrl;
        }
        foreach ($targets as $kind => $url) {
            if ($row->{$kind.'_path'}) {
                continue;
            }
            if (! $url) {
                $errors[] = "missing_{$kind}_url";
                continue;
            }
            try {
                $file = $this->download($url, $kind, $row->disk, $prefix);
                $update = [$kind.'_path' => $file['path'], 'captured_at' => now('UTC'), 'updated_at' => now('UTC')];
                if ($kind === 'image') {
                    $update += ['mime_type' => $file['mime'], 'width' => $file['width'], 'height' => $file['height']];
                }
                // Persist the cover before attempting optional video. A failed
                // video/storage request must not discard an already saved image.
                $query->update($update);
                $row->{$kind.'_path'} = $file['path'];
                $saved = true;
            } catch (Throwable $error) {
                // Only our fixed codes are persisted; third-party exception text
                // can contain a signed CDN URL, OAuth credential, or response body.
                $reason = $error instanceof RuntimeException && (in_array($error->getMessage(), [
                    'story_invalid_media_url', 'story_invalid_size_cap', 'story_temporary_file_failed',
                    'story_size_cap_exceeded', 'story_download_timeout', 'story_temporary_write_failed',
                    'story_invalid_media_content', 'story_storage_write_failed',
                ], true) || preg_match('/^story_http_[1-5][0-9]{2}$/D', $error->getMessage()))
                    ? $error->getMessage() : 'story_transfer_failed';
                $errors[] = $kind.':'.$reason;
            }
        }
        $query->update(['error' => $errors ? implode(';', array_unique($errors)) : null, 'updated_at' => now('UTC')]);
        return ['captured' => (int) $saved, 'errors' => (int) (bool) $errors];
    }

    private function download(string $url, string $kind, string $disk, string $prefix): array
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('story_invalid_media_url');
        }
        $cap = (int) config('peachy-stories.max_'.$kind.'_bytes', $kind === 'image' ? 20971520 : 104857600);
        if ($cap <= 0) {
            throw new RuntimeException('story_invalid_size_cap');
        }
        $response = Http::connectTimeout(10)->timeout(120)
            ->withOptions(['stream' => true, 'read_timeout' => 10, 'allow_redirects' => false])->get($url);
        $body = $response->toPsrResponse()->getBody();
        $temporary = tmpfile();
        if (! $temporary) {
            $body->close();
            throw new RuntimeException('story_temporary_file_failed');
        }
        try {
            if (! $response->successful()) {
                throw new RuntimeException('story_http_'.$response->status());
            }
            if ((int) $response->header('Content-Length') > $cap) {
                throw new RuntimeException('story_size_cap_exceeded');
            }
            $bytes = 0;
            $deadline = microtime(true) + 120;
            while (! $body->eof()) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('story_download_timeout');
                }
                $chunk = $body->read(65536);
                $bytes += strlen($chunk);
                if ($bytes > $cap) {
                    throw new RuntimeException('story_size_cap_exceeded');
                }
                if (fwrite($temporary, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('story_temporary_write_failed');
                }
            }
            $path = stream_get_meta_data($temporary)['uri'];
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            $ext = match ($mime) {
                'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
                'video/mp4' => 'mp4', default => null,
            };
            $dimensions = $kind === 'image' ? @getimagesize($path) : null;
            if (! $bytes || ! $ext || ! str_starts_with($mime, $kind.'/') || ($kind === 'image' && ! $dimensions)) {
                throw new RuntimeException('story_invalid_media_content');
            }
            $key = $prefix.'.'.$ext;
            rewind($temporary);
            $filesystem = Storage::disk($disk);
            if ($filesystem->getAdapter() instanceof \League\Flysystem\AwsS3V3\AwsS3V3Adapter) {
                // Bound this capture's S3 writes without changing Mixpost's shared
                // disk/client. Keep the same bucket, root, options and credentials.
                $settings = config('filesystems.disks.'.$disk);
                $settings['http'] = array_replace($settings['http'] ?? [], ['connect_timeout' => 10, 'timeout' => 60]);
                $settings['retries'] = 0;
                $filesystem = Storage::build($settings);
            }
            if (! $filesystem->put($key, $temporary, ['visibility' => 'public', 'ContentType' => $mime])) {
                throw new RuntimeException('story_storage_write_failed');
            }
            return ['path' => $key, 'mime' => $mime, 'width' => $dimensions[0] ?? null, 'height' => $dimensions[1] ?? null];
        } finally {
            $body->close();
            fclose($temporary);
        }
    }
}
