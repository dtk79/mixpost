<?php

namespace Inovector\Mixpost\Actions\Post;

use Inovector\Mixpost\Concerns\UsesSocialProviderManager;
use Inovector\Mixpost\Enums\SocialProviderContentType;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Events\Post\PostPublished;
use Inovector\Mixpost\Events\Post\PostPublishedFailed;
use Inovector\Mixpost\Models\Account;
use Inovector\Mixpost\Models\Post;
use Inovector\Mixpost\Support\PeachyPostVersionContent;
use Inovector\Mixpost\Support\PostContentParser;
use Inovector\Mixpost\Support\SocialProviderResponse;

class AccountPublishPost
{
    use UsesSocialProviderManager;

    public function __invoke(Account $account, Post $post): SocialProviderResponse
    {
        $parser = new PostContentParser($account, $post);

        $content = PeachyPostVersionContent::withoutEmptyAdditionalItems($parser->getVersionContent());
        $options = $parser->getVersionOptions();
        $hasAdditionalContent = count($content) > 1;

        if (empty($content)) {
            $errors = ['This account version has no content.'];

            $post->insertErrors($account, $errors);

            return new SocialProviderResponse(SocialProviderResponseStatus::ERROR, $errors);
        }

        $providerConnection = $this->connectProvider($account);

        // Publish the main post
        $lastResponse = $providerConnection->publishPost(
            text: $parser->formatBody($content[0]['body']),
            media: $parser->formatMedia($content[0]['media']),
            params: array_merge($options, [
                'url' => $content[0]['url'] ?? '',
                'video_thumbs' => $content[0]['video_thumbs'] ?? '',
            ])
        );

        $firstResponse = $lastResponse;

        if ($lastResponse->hasError()) {
            $post->insertErrors($account, $lastResponse->context());

            PostPublishedFailed::dispatch($post);

            return $lastResponse;
        }

        $post->insertProviderData($account, $lastResponse);

        // If the content type is comments and are additional content items, publish only the first item
        if ($providerConnection::contentType() === SocialProviderContentType::COMMENTS && $hasAdditionalContent) {
            $lastResponse = $providerConnection->publishComment(
                text: $parser->formatBody($content[1]['body']),
                postId: $lastResponse->id(),
                params: $options,
            );

            if ($lastResponse->hasError()) {
                $post->insertErrors($account, $lastResponse->context());

                PostPublishedFailed::dispatch($post);

                return $lastResponse;
            }
        }

        // If the content type is thread and there are additional content items, publish them
        if ($providerConnection::contentType() === SocialProviderContentType::THREAD && $hasAdditionalContent) {
            foreach (array_slice($content, 1) as $contentItem) {
                $lastResponse = $this->connectProvider($account)->publishPost(
                    text: $parser->formatBody($contentItem['body']),
                    media: $parser->formatMedia($contentItem['media']),
                    params: array_merge($options, [
                        'url' => $contentItem['url'] ?? '',
                        'first_response' => $firstResponse,
                        'last_response' => $lastResponse,
                        'last_id' => $lastResponse->id(),
                    ])
                );

                if ($lastResponse->hasError()) {
                    $post->insertErrors($account, $lastResponse->context());

                    PostPublishedFailed::dispatch($post);

                    return $lastResponse;
                }

                if ($lastResponse->id()) {
                    $post->appendProviderThreadPostId($account, $lastResponse->id());
                }
            }
        }

        PostPublished::dispatch($post);

        return $lastResponse;
    }
}
