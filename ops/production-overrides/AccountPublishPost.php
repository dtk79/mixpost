<?php

namespace Inovector\Mixpost\Actions\Post;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Inovector\Mixpost\Concerns\UsesSocialProviderManager;
use Inovector\Mixpost\Enums\SocialProviderContentType;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Events\Post\PostPublished;
use Inovector\Mixpost\Events\Post\PostPublishedFailed;
use Inovector\Mixpost\Jobs\OptimizeSocialVideoMediaJob;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Post;
use Inovector\Mixpost\Support\PeachyPostVersionContent;
use Inovector\Mixpost\Support\PostContentParser;
use Inovector\Mixpost\Support\PostRequirements;
use Inovector\Mixpost\Support\PostRequirementViolation;
use Inovector\Mixpost\Support\PublishCheckpoint;
use Inovector\Mixpost\Support\SocialProviderResponse;

/**
 * Publishes one account's version of a post, in as many attempts as it takes.
 *
 * A post can be several items long — a main post plus comments or thread replies — and any of them
 * can leave the platform mid-flight. Every item that succeeds has its provider id persisted
 * immediately, and this action never republishes an item that already has one. That is what makes a
 * retry safe: it continues a partially published post instead of duplicating what is already live.
 */
class AccountPublishPost
{
    use UsesSocialProviderManager;

    public function prepareSocialVideos(Account $account, Post $post): bool
    {
        if (! in_array($account->provider, ['instagram', 'instagram_standalone', 'twitter', 'threads', 'bluesky'], true)) {
            return true;
        }

        $parser = new PostContentParser($account, $post);
        $ids = collect($parser->getVersionContent())->pluck('media')->flatten()->unique()->values()->all();
        $ready = true;

        foreach ($parser->formatMedia($ids) as $media) {
            if ($media->isVideo() && ! $media->getConversion('social_video')) {
                OptimizeSocialVideoMediaJob::dispatch($media->id);
                $ready = false;
            }
        }

        return $ready;
    }

    public function __invoke(Account $account, Post $post): SocialProviderResponse
    {
        $parser = new PostContentParser($account, $post);

        $content = PeachyPostVersionContent::withoutEmptyAdditionalItems($parser->getVersionContent());
        $options = $parser->getVersionOptions();

        if (empty($content)) {
            return $this->fail($post, $account, ['This account version has no content.']);
        }

        $checkpoint = PublishCheckpoint::fromArray($post->publishState($account));

        if (! $checkpoint && ! $post->publishedProviderPostIds($account) && $unmet = $this->unmetRequirements($post, $account)) {
            return $this->fail($post, $account, $unmet);
        }

        // Direct invocations must obey the same preflight as queued publishing.
        if (! $checkpoint && ! $this->prepareSocialVideos($account, $post)) {
            return new SocialProviderResponse(SocialProviderResponseStatus::ERROR, ['social_video_optimization_pending']);
        }

        $providerConnection = $this->connectProvider($account);

        if ($checkpoint?->expired()) {
            if (! $this->settleExpired($post, $account, $checkpoint)) {
                return $this->fail($post, $account, ['upload_stalled']);
            }

            $checkpoint = null;
        }

        $publishedIds = $post->publishedProviderPostIds($account);
        $publishedResponse = null;

        if (empty($publishedIds)) {
            $response = $this->withConnectionGuard(fn () => $providerConnection->publishPost(
                text: $parser->formatBody($content[0]['body']),
                media: $parser->formatMedia($content[0]['media']),
                params: array_merge($options, [
                    'url' => $content[0]['url'] ?? '',
                    'video_thumbs' => $content[0]['video_thumbs'] ?? '',
                    'resume' => $this->providerStateFor($checkpoint, 0),
                ])
            ));

            if ($unfinished = $this->handleUnfinished($post, $account, $response, 0, $checkpoint)) {
                return $unfinished;
            }

            $post->insertProviderData($account, $response);

            $publishedResponse = $response;
        }

        // A platform can accept a post and still withhold its id — TikTok does, until moderation
        // approves the video. The stored row then has nothing to rehydrate from, so the response the
        // provider just returned stands in for it.
        $firstResponse = $post->providerResponseFor($account, 0) ?? $publishedResponse;
        $lastResponse = $firstResponse;

        $hasAdditionalContent = count($content) > 1;

        // If the content type is comments and are additional content items, publish only the first item
        if ($providerConnection::contentType() === SocialProviderContentType::COMMENTS && $hasAdditionalContent) {
            if (! $post->commentWasPublished($account)) {
                $response = $this->withConnectionGuard(fn () => $providerConnection->publishComment(
                    text: $parser->formatBody($content[1]['body']),
                    postId: $lastResponse->id(),
                    params: $options,
                ));

                if ($unfinished = $this->handleUnfinished($post, $account, $response, 1, $checkpoint)) {
                    return $unfinished;
                }

                $post->markCommentPublished($account);

                $lastResponse = $response;
            }
        }

        // If the content type is thread and there are additional content items, publish them
        if ($providerConnection::contentType() === SocialProviderContentType::THREAD && $hasAdditionalContent) {
            $items = array_slice($content, 1);

            foreach ($items as $index => $contentItem) {
                $itemIndex = $index + 1;

                // Already live from an earlier attempt — carry its context forward and move on.
                if (isset($publishedIds[$itemIndex])) {
                    $lastResponse = $post->providerResponseFor($account, $itemIndex) ?? $lastResponse;

                    continue;
                }

                $previousResponse = $lastResponse;

                $response = $this->withConnectionGuard(fn () => $this->connectProvider($account)->publishPost(
                    text: $parser->formatBody($contentItem['body']),
                    media: $parser->formatMedia($contentItem['media']),
                    params: array_merge($options, [
                        'url' => $contentItem['url'] ?? '',
                        'first_response' => $firstResponse,
                        'last_response' => $previousResponse,
                        'last_id' => $previousResponse->id(),
                        'resume' => $this->providerStateFor($checkpoint, $itemIndex),
                    ])
                ));

                if ($unfinished = $this->handleUnfinished($post, $account, $response, $itemIndex, $checkpoint)) {
                    return $unfinished;
                }

                if ($response->id()) {
                    $post->appendProviderThreadPost($account, $response);
                }

                $lastResponse = $response;
            }
        }

        $post->clearPublishState($account);
        $post->markAccountPublished($account);

        PostPublished::dispatch($post, $account);

        return $lastResponse;
    }

    /**
     * Decide whether an item's response ends this attempt. Returns the response to hand back when it
     * does — a pending checkpoint, a rate limit to wait out, or a recorded failure — and null when
     * the item is done and publishing should carry on.
     */
    private function handleUnfinished(
        Post $post,
        Account $account,
        SocialProviderResponse $response,
        int $itemIndex,
        ?PublishCheckpoint $checkpoint
    ): ?SocialProviderResponse {
        if ($response->isPending()) {
            $post->storePublishState(
                $account,
                PublishCheckpoint::fromResponse($response, $itemIndex, $checkpoint)->toArray()
            );

            return $response;
        }

        // Being rate limited is not a failure: the job releases itself and comes back. Recording it
        // would fire a publish-failed webhook for a post that is still on its way.
        if ($response->hasExceededRateLimit()) {
            return $response;
        }

        if ($response->hasError()) {
            return $this->fail($post, $account, $response->context(), $response);
        }

        return null;
    }

    /**
     * A checkpoint that ran out of time normally means the post never made it, which is a failure.
     * The exception is one the provider marked live: the post is on the platform and only metadata
     * it can live without was still outstanding, so the publication is recorded with what there is.
     * Failing it would show a live post as failed and let a retry publish a second copy.
     */
    private function settleExpired(Post $post, Account $account, PublishCheckpoint $checkpoint): bool
    {
        if (! $checkpoint->live || ! $checkpoint->isFor(0)) {
            return false;
        }

        $post->insertProviderData($account, new SocialProviderResponse(SocialProviderResponseStatus::OK, []));
        $post->clearPublishState($account);

        return true;
    }

    /**
     * A post can change after it was scheduled — edited in the editor, where every keystroke is saved,
     * or left behind by its account, its media or its options. Whatever the platform cannot publish is
     * stopped here, before anything reaches it: a thread would otherwise go live up to the post at
     * fault and stay broken, and the platform's own error says less than the requirement does.
     *
     * Only a first attempt is checked. Once part of the post is on the platform, stopping would not
     * take it back.
     *
     * @return array<int, string>
     */
    private function unmetRequirements(Post $post, Account $account): array
    {
        return array_map(
            fn (PostRequirementViolation $violation) => $violation->message,
            (new PostRequirements($post->accounts, $post->versions->map(fn ($version) => [
                'account_id' => $version->account_id,
                'content' => PeachyPostVersionContent::withoutEmptyAdditionalItems($version->content ?? []),
                'options' => $version->options ?? [],
            ])->all()))->violationsFor($account)
        );
    }

    private function fail(Post $post, Account $account, array $errors, ?SocialProviderResponse $response = null): SocialProviderResponse
    {
        $post->clearPublishState($account);
        $post->insertErrors($account, $errors);

        PostPublishedFailed::dispatch($post, $account);

        return $response ?? new SocialProviderResponse(SocialProviderResponseStatus::ERROR, $errors);
    }

    private function providerStateFor(?PublishCheckpoint $checkpoint, int $itemIndex): ?array
    {
        return $checkpoint?->isFor($itemIndex) ? $checkpoint->toProviderState() : null;
    }

    /**
     * A dropped connection mid-upload would otherwise escape as an uncaught exception and kill the
     * job, leaving no error on the account and no publish-failed webhook — the post would still be
     * settled as published by the batch. Turning it into a response keeps the outcome visible.
     */
    private function withConnectionGuard(Closure $publish): SocialProviderResponse
    {
        try {
            return $publish();
        } catch (ConnectionException) {
            return new SocialProviderResponse(SocialProviderResponseStatus::ERROR, ['connection_lost']);
        }
    }
}
