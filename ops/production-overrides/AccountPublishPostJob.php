<?php

namespace Inovector\Mixpost\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inovector\Mixpost\Actions\Post\AccountPublishPost;
use Inovector\Mixpost\Concerns\Job\HasSocialProviderJobRateLimit;
use Inovector\Mixpost\Concerns\Job\OnPublishPostQueue;
use Inovector\Mixpost\Contracts\QueueWorkspaceAware;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Post;

class AccountPublishPostJob implements QueueWorkspaceAware, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use HasSocialProviderJobRateLimit;
    use OnPublishPostQueue;

    public $deleteWhenMissingModels = true;

    public Account $account;

    public Post $post;

    public function __construct(Account $account, Post $post)
    {
        $this->account = $account;
        $this->post = $post;
        $this->onQueue($this->viaQueue());
    }

    public function failed(?\Throwable $exception): void
    {
        if (! $this->post->accounts()->whereKey($this->account->id)->first()?->pivot?->provider_post_id) {
            $this->post->insertErrors($this->account, ['Publishing could not complete. Check video preparation and the publishing job logs before retrying.']);
        }
    }

    public function handle(AccountPublishPost $accountPublishPost): void
    {
        if ($this->batch()->cancelled()) {
            return;
        }

        if ($this->post->isInHistory()) {
            return;
        }

        if ($this->post->trashed()) {
            $this->post->setDraft();
            $this->batch()->cancel();

            return;
        }

        if (! $this->account->isServiceActive()) {
            $this->post->insertErrors($this->account, ['service_disabled']);

            return;
        }

        if ($this->account->isUnauthorized()) {
            $this->post->insertErrors($this->account, ['access_token_expired']);

            return;
        }

        if ($retryAfter = $this->rateLimitExpiration()) {
            $this->release($retryAfter);

            return;
        }

        // Wait for queued video conversions so providers receive the final MP4 file
        if ($this->post->hasProcessingMedia()) {
            $this->release(30);

            return;
        }

        // Never resubmit an account that already has a remote publication ID.
        if ($this->post->accounts()->whereKey($this->account->id)->first()?->pivot?->provider_post_id) {
            return;
        }

        $preparationKey = "social-video-wait:{$this->post->id}:{$this->account->id}";
        if (! $accountPublishPost->prepareSocialVideos($this->account, $this->post)) {
            Cache::add($preparationKey, time(), 7200);
            if (time() - Cache::get($preparationKey) >= 1800) {
                $this->post->insertErrors($this->account, ['Video preparation did not finish within 30 minutes. The original video was not sent to the provider.']);
                Cache::forget($preparationKey);

                return;
            }
            $this->release(30);

            return;
        }
        Cache::forget($preparationKey);

        $response = $accountPublishPost($this->account, $this->post);

        $retryKey = "instagram-upload-retry:{$this->post->id}:{$this->account->id}";
        if ($this->canRetryInstagramUpload($response)) {
            Cache::add($retryKey, 0, 86400);
            $attempt = Cache::increment($retryKey);
            if ($attempt <= 2) {
                Log::warning('mixpost.instagram_upload_retry', [
                    'post_id' => $this->post->id,
                    'account_id' => $this->account->id,
                    'container_id' => $response->context()['container_id'],
                    'retry' => $attempt,
                ]);
                $this->release(60 * $attempt);

                return;
            }
        }

        if (! $response->hasError()) {
            Cache::forget($retryKey);
        }

        if ($response->isUnauthorized()) {
            $this->account->setUnauthorized();
            $this->delete();

            return;
        }

        if ($response->hasExceededRateLimit()) {
            $this->storeRateLimitExceeded($response->retryAfter(), $response->isAppLevel());
            $this->release($response->retryAfter());

            return;
        }

        if ($response->rateLimitAboutToBeExceeded()) {
            $this->storeRateLimitExceeded($response->retryAfter(), $response->isAppLevel());
        }

        if ($response->hasError()) {
            // We are deleting this job from queue because all info about the failed post is in the `mixpost_post_accounts` table.
            $this->delete();
        }
    }

    private function canRetryInstagramUpload(\Inovector\Mixpost\Support\SocialProviderResponse $response): bool
    {
        $context = $response->context();

        // Only a terminal, unpublished upload failure is safe to recreate.
        // Never retry media_publish errors, timeouts, or unknown container states.
        return $this->account->provider === 'instagram'
            && $response->hasError()
            && ($context['phase'] ?? null) === 'instagram_container_processing'
            && ($context['container_status'] ?? null) === 'ERROR'
            && ! empty($context['container_id'])
            && preg_match('/\b2207082\b/', json_encode($context['errors'] ?? [])) === 1;
    }
}
