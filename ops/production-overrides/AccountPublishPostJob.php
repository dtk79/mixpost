<?php

namespace Inovector\Mixpost\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inovector\Mixpost\Actions\Post\AccountPublishPost;
use Inovector\Mixpost\Concerns\Job\HasSocialProviderJobRateLimit;
use Inovector\Mixpost\Concerns\Job\OnPublishPostQueue;
use Inovector\Mixpost\Contracts\QueueWorkspaceAware;
use Inovector\Mixpost\Enums\PostAccountStatus;
use Inovector\Mixpost\Events\Post\PostPublishedFailed;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Post;

class AccountPublishPostJob implements QueueWorkspaceAware, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use HasSocialProviderJobRateLimit;
    use OnPublishPostQueue;

    /**
     * Longest a resuming attempt is parked when the provider is rate limited. Status polls are cheap
     * and metered separately from publishing, so they must not inherit the full quota window.
     */
    protected const RESUME_RATE_LIMIT_CAP = 60;

    /**
     * Longest a post waits on media that is still being converted. A conversion is allowed ten
     * minutes, so an hour leaves room for a busy media queue; past that the conversion is not
     * coming — its worker is down, or the job was lost — and waiting on would only keep the post
     * silently "publishing" until the job itself expires a day later.
     */
    protected const MEDIA_PROCESSING_LIMIT_MINUTES = 60;

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
        if ($this->post->accountPublishStatus($this->account) !== PostAccountStatus::PUBLISHED) {
            $this->post->insertErrors($this->account, ['Publishing could not complete. Check video preparation and the publishing job logs before retrying.']);
        }
    }

    public function handle(AccountPublishPost $accountPublishPost): void
    {
        if ($this->batch()->cancelled()) {
            $this->post->releaseAccount($this->account);

            return;
        }

        // Asked of the account, not the post: a retry sends one account of a post that already
        // failed, and must still go out.
        if ($this->accountHasOutcome()) {
            return;
        }

        if ($this->post->trashed()) {
            $this->post->setDraft();
            $this->post->releaseAccount($this->account);
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

        $isResuming = $this->post->hasPublishState($this->account);

        if ($retryAfter = $this->rateLimitExpiration()) {
            // Content already handed to the platform only needs a cheap status poll, so it must not
            // be parked behind a full publishing-quota window.
            $this->release($isResuming ? min($retryAfter, self::RESUME_RATE_LIMIT_CAP) : $retryAfter);

            return;
        }

        // Wait for queued video conversions so providers receive the final MP4 file
        if (! $isResuming && $processingSince = $this->post->mediaProcessingSince()) {
            if ($this->mediaProcessingStalled($processingSince)) {
                $this->failOnStalledMedia();

                return;
            }

            $this->release(30);

            return;
        }

        $preparationKey = "social-video-wait:{$this->post->id}:{$this->account->id}";
        if (! $isResuming && ! $accountPublishPost->prepareSocialVideos($this->account, $this->post)) {
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
                // Retrying the same claimed run must not increment publishing_runs.
                $this->post->resetAccountPublishState($this->account);
                $this->post->setAccountPublishStatus($this->account, PostAccountStatus::PUBLISHING);
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

        if ($response->isPending()) {
            $this->release($response->retryAfter());

            return;
        }

        if ($response->hasError()) {
            // We are deleting this job from queue because all info about the failed post is in the `mixpost_post_accounts` table.
            $this->delete();
        }
    }

    protected function accountHasOutcome(): bool
    {
        return in_array($this->post->accountPublishStatus($this->account), [
            PostAccountStatus::PUBLISHED,
            PostAccountStatus::FAILED,
        ], true);
    }

    protected function mediaProcessingStalled(Carbon $processingSince): bool
    {
        return $processingSince->lte(Carbon::now('UTC')->subMinutes(self::MEDIA_PROCESSING_LIMIT_MINUTES));
    }

    // Reported the way any failed publication is, so the failure reaches the post's activity,
    // its notifications and webhooks instead of surfacing a day later as an expired job.
    protected function failOnStalledMedia(): void
    {
        $this->post->insertErrors($this->account, ['media_processing_stalled']);

        PostPublishedFailed::dispatch($this->post, $this->account);
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
