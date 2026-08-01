<?php

namespace Inovector\Mixpost\SocialProviders\Twitter\Concerns;

use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Inovector\Mixpost\Enums\SocialProviderResponseStatus;
use Inovector\Mixpost\Mixpost;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Support\SocialProviderResponse;

trait ManagesResources
{
    public function getAccount(): SocialProviderResponse
    {
        $response = $this->connection->get('users/me', ['user.fields' => 'profile_image_url,created_at,verified,verified_type']);

        return $this->buildResponse($response, function () use ($response) {
            return [
                'id' => $response->data->id,
                'name' => $response->data->name,
                'username' => $response->data->username,
                'image' => str_replace('normal', '400x400', $response->data->profile_image_url),
                'data' => [
                    'verified' => $response->data->verified,
                ],
            ];
        });
    }

    public function publishPost(string $text, Collection $media, array $params = []): SocialProviderResponse
    {
        try {
            $mediaResult = $this->uploadMedia($media);
        } catch (Exception $exception) {
            return $this->response(SocialProviderResponseStatus::ERROR, [$exception->getMessage()]);
        }

        if (! empty($mediaResult['errors'])) {
            return $this->response(SocialProviderResponseStatus::ERROR, $mediaResult['errors']);
        }

        return match ($this->getTier()) {
            'legacy' => $this->storePostWithApiV1($text, $mediaResult, $params),
            default => $this->storePostWithApiV2($text, $mediaResult, $params),
        };
    }

    public function uploadMedia(Collection $media): array
    {
        $this->connection->setApiVersion('1.1');

        $ids = [];
        $errors = [];

        foreach ($media as $item) {
            $chunkUpload = $item->isVideo() || $item->isImageGif();

            if (! $chunkUpload) {
                $result = $this->connection->upload('media/upload', [
                    'media' => $item->isLocalAdapter() ? $item->getFullPath() : $item->getUrl(),
                    'media_type' => $item->mime_type,
                    'media_category' => 'tweet_image',
                    'total_bytes' => $item->size,
                ]);
            }

            if ($chunkUpload) {
                $uploadItem = $this->twitterMediaItemForUpload($item);

                /** @var string|array $mediaFilePath * */
                $downloadStartedAt = microtime(true);
                $mediaFilePath = $uploadItem->isLocalAdapter() ?
                    $uploadItem->getFullPath() :
                    $uploadItem->downloadToTemp();
                $this->logPublishTiming('media_download', $uploadItem, $downloadStartedAt);

                $uploadStartedAt = microtime(true);
                $result = $this->connection->upload('media/upload', [
                    'media' => $mediaFilePath['fullPath'] ?? $mediaFilePath,
                    'media_type' => $uploadItem->mime_type,
                    'media_category' => $uploadItem->isImageGif() ? 'tweet_gif' : 'tweet_video',
                    'total_bytes' => $uploadItem->size,
                ], ['chunkedUpload' => true]);
                $this->logPublishTiming('media_upload', $uploadItem, $uploadStartedAt);

                if (isset($mediaFilePath['temporaryDirectory'])) {
                    $mediaFilePath['temporaryDirectory']->delete();
                }
            }

            if (! $result) {
                $errors[] = $result;

                continue;
            }

            // Check status of uploaded media
            if (isset($result->processing_info)) {
                $processingStartedAt = microtime(true);
                $state = $result->processing_info->state;
                $sleepSeconds = $result->processing_info->check_after_secs;

                do {
                    sleep($sleepSeconds);

                    $mediaStatus = $this->connection->mediaStatus($result->media_id);

                    $state = $mediaStatus->processing_info->state;
                    $sleepSeconds = $mediaStatus->processing_info->check_after_secs ?? 1;

                } while (in_array($state, ['pending', 'in_progress']));

                $this->logPublishTiming('media_processing', $item, $processingStartedAt, [
                    'state' => $state,
                ]);

                if ($state === 'failed') {
                    $errors[] = 'upload_failed';

                    continue;
                }
            }

            if ($item->alt_text) {
                $this->connection->mediaMetadataCreate(
                    media_id: $result->media_id_string,
                    alt_text: $item->alt_text
                );
            }

            $ids[] = $result->media_id_string;
        }

        return [
            'ids' => $ids,
            'errors' => $errors,
        ];
    }

    protected function twitterMediaItemForUpload(Media $mediaItem): Media
    {
        $conversion = $mediaItem->getConversion('social_video')
            ?: $mediaItem->getConversion('instagram_reel');

        if (! $conversion || ! $mediaItem->isVideo()) {
            return $mediaItem;
        }

        $uploadItem = $mediaItem->replicate();
        $uploadItem->forceFill([
            'id' => $mediaItem->id,
            'disk' => $conversion['disk'],
            'path' => $conversion['path'],
            'size' => $conversion['size'] ?? $mediaItem->size,
            'size_total' => $conversion['size'] ?? $mediaItem->size,
            'mime_type' => 'video/mp4',
        ]);

        return $uploadItem;
    }

    protected function storePostWithApiV1(string $text, array $mediaResult, array $params): SocialProviderResponse
    {
        $this->connection->setApiVersion('1.1');

        $postParameters = ['status' => $text];

        if ($lastId = $params['last_id'] ?? null) {
            $postParameters['in_reply_to_status_id'] = $lastId;
        }

        if (! empty($mediaResult['ids'])) {
            $postParameters['media_ids'] = implode(',', $mediaResult['ids']);
        }

        $postStartedAt = microtime(true);
        $postResult = $this->connection->post('statuses/update', $postParameters);
        $this->logPublishTiming('tweet_create_v1', null, $postStartedAt, [
            'http_code' => $this->connection->getLastHttpCode(),
        ]);

        $httpCode = $this->connection->getLastHttpCode();

        if ($httpCode !== 201) {
            Mixpost::reportException(new Exception(json_encode($postResult) ?: 'An error occurred while creating the post.'));

            $error = $postResult->errors[0]->message ?? (json_encode($postResult) ?: 'An error occurred while creating the post.');

            return $this->response(SocialProviderResponseStatus::ERROR, [$error]);
        }

        return $this->buildResponse($postResult, function () use ($postResult) {
            return [
                'id' => $postResult->id,
            ];
        });
    }

    protected function storePostWithApiV2(string $text, array $mediaResult, array $params): SocialProviderResponse
    {
        $this->connection->setApiVersion('2');

        $postParameters = ['text' => $text];

        if ($lastId = $params['last_id'] ?? null) {
            $postParameters['reply']['in_reply_to_tweet_id'] = $lastId;
        }

        if (! empty($mediaResult['ids'])) {
            $postParameters['media']['media_ids'] = $mediaResult['ids'];
        }

        $postStartedAt = microtime(true);
        $postResult = $this->connection->post('tweets', $postParameters, ['jsonPayload' => true]);
        $this->logPublishTiming('tweet_create_v2', null, $postStartedAt, [
            'http_code' => $this->connection->getLastHttpCode(),
        ]);

        if ($this->connection->getLastHttpCode() !== 201) {
            Mixpost::reportException(new Exception(json_encode($postResult) ?: 'An error occurred while creating the post.'));

            $title = $postResult->title ?? '';
            $detail = $postResult->detail ?? '';

            $error = $title === 'CreditsDepleted'
                ? $title
                : (trim("$title: $detail") ?: 'An error occurred while creating the post.');

            return $this->response(SocialProviderResponseStatus::ERROR, [$error]);
        }

        return $this->buildResponse($postResult, function () use ($postResult) {
            return [
                'id' => $postResult->data->id,
            ];
        });
    }

    public function getAccountMetrics(): SocialProviderResponse
    {
        $response = $this->connection->get('users/me', ['user.fields' => 'public_metrics']);

        return $this->buildResponse($response, function () use ($response) {
            return [
                'followers_count' => $response->data->public_metrics->followers_count,
                'following_count' => $response->data->public_metrics->following_count,
                'tweet_count' => $response->data->public_metrics->tweet_count,
                'listed_count' => $response->data->public_metrics->listed_count,
            ];
        });
    }

    public function getUserTweetTimeline(string $userId, string $paginationToken = ''): SocialProviderResponse
    {
        $params = [
            'tweet.fields' => 'article,public_metrics,non_public_metrics,created_at,in_reply_to_user_id,attachments',
            'expansions' => 'article.cover_media,article.media_entities,attachments.media_keys',
            'media.fields' => 'type,url,preview_image_url',
            'exclude' => 'retweets,replies',
            'max_results' => 100,
        ];

        if ($paginationToken) {
            $params['pagination_token'] = $paginationToken;
        }

        $response = $this->connection->get("users/$userId/tweets", $params);
        $this->removeUnavailableHistoricalMetricErrors($response, $userId);

        return $this->buildResponse($response, function () use ($response) {
            return [
                'data' => $response->data ?? [],
                'includes' => $response->includes ?? null,
                'meta' => $response->meta ?? null,
            ];
        });
    }

    // Recent search for engagement directed at the account: replies to it (`to:`, caught regardless of
    // whether the reply @-mentions us — unlike the mentions timeline) plus standalone mentions (`@`),
    // excluding the account's own tweets and retweets.
    public function getReplies(string $username, array $params = []): SocialProviderResponse
    {
        $query = [
            'query' => "(to:$username OR @$username) -from:$username -is:retweet",
            'tweet.fields' => 'created_at,text,referenced_tweets,in_reply_to_user_id,conversation_id',
            'expansions' => 'author_id',
            'user.fields' => 'username,name,profile_image_url',
            'max_results' => 100,
        ];

        if ($sinceId = $params['since_id'] ?? null) {
            $query['since_id'] = $sinceId;
        }

        if ($nextToken = $params['next_token'] ?? null) {
            $query['next_token'] = $nextToken;
        }

        $response = $this->connection->get('tweets/search/recent', $query);

        return $this->buildResponse($response, function () use ($response) {
            return [
                'data' => $response->data ?? [],
                'includes' => $response->includes ?? null,
                'meta' => $response->meta ?? null,
            ];
        });
    }

    public function deletePost(string $id, array $params = []): SocialProviderResponse
    {
        $this->connection->setApiVersion('2');

        $response = $this->connection->delete("tweets/$id");

        return $this->buildResponse($response);
    }

    private function removeUnavailableHistoricalMetricErrors(object $response, string $userId): void
    {
        if (! isset($response->data, $response->errors) || ! is_array($response->errors)) {
            return;
        }

        $unexpectedErrors = array_values(array_filter(
            $response->errors,
            fn (mixed $error): bool => ! $this->isUnavailableHistoricalMetricError($error)
        ));

        $ignoredErrorCount = count($response->errors) - count($unexpectedErrors);

        if ($ignoredErrorCount === 0) {
            return;
        }

        if ($unexpectedErrors) {
            $response->errors = $unexpectedErrors;
        } else {
            unset($response->errors);
        }

        Log::info('mixpost.twitter_ignored_historical_metric_errors', [
            'provider_user_id' => $userId,
            'ignored_error_count' => $ignoredErrorCount,
            'result_count' => count($response->data),
        ]);
    }

    private function isUnavailableHistoricalMetricError(mixed $error): bool
    {
        if (! is_object($error)
            || ($error->title ?? null) !== 'Disallowed Resource'
            || ($error->resource_type ?? null) !== 'tweet') {
            return false;
        }

        return preg_match(
            "/^The 'non_public_metrics\\.(engagements|impression_count|url_link_clicks|user_profile_clicks)' field cannot be queried for Tweets older than 30 days\\.$/",
            (string) ($error->detail ?? '')
        ) === 1;
    }

    private function logPublishTiming(string $phase, mixed $media, float $startedAt, array $context = []): void
    {
        Log::info('mixpost.twitter_publish_timing', array_merge([
            'phase' => $phase,
            'elapsed_seconds' => round(microtime(true) - $startedAt, 3),
            'account_id' => $this->values['account_id'] ?? null,
            'media_id' => $media->id ?? null,
            'media_bytes' => $media->size ?? null,
            'media_mime_type' => $media->mime_type ?? null,
        ], $context));
    }
}
