<?php

namespace Inovector\Mixpost\SocialProviders\Google;

use Closure;
use Inovector\Mixpost\Abstracts\SocialProvider;
use Inovector\Mixpost\Concerns\OAuth\RefreshesAccessToken;
use Inovector\Mixpost\Contracts\AccountResource;
use Inovector\Mixpost\Contracts\SocialProviderPostOptions;
use Inovector\Mixpost\Services\GoogleService;
use Inovector\Mixpost\SocialProviders\Google\Concerns\ManagesOAuth;
use Inovector\Mixpost\SocialProviders\Google\Concerns\ManagesYoutubeAccountDeletion;
use Inovector\Mixpost\SocialProviders\Google\Concerns\ManagesYoutubeJobs;
use Inovector\Mixpost\SocialProviders\Google\Concerns\ManagesYoutubeResources;
use Inovector\Mixpost\SocialProviders\Google\Concerns\UsesResponseBuilder;
use Inovector\Mixpost\SocialProviders\Google\Support\YoutubePostOptions;
use Inovector\Mixpost\Support\SocialProviderPostConfigs;

class YoutubeProvider extends SocialProvider
{
    public bool $onlyUserAccount = false;

    public array $callbackResponseKeys = ['code'];

    use ManagesOAuth;
    use ManagesYoutubeAccountDeletion;
    use ManagesYoutubeJobs;
    use ManagesYoutubeResources;
    use RefreshesAccessToken;
    use UsesResponseBuilder;

    public static function name(): string
    {
        return 'YouTube';
    }

    public static function service(): string
    {
        return GoogleService::class;
    }

    protected function getScopes(): array
    {
        return [
            'https://www.googleapis.com/auth/youtube',
            'https://www.googleapis.com/auth/youtube.upload',
            'https://www.googleapis.com/auth/yt-analytics.readonly',
        ];
    }

    public static function postConfigs(Closure $getAccountData): SocialProviderPostConfigs
    {
        return SocialProviderPostConfigs::make()
            ->simultaneousPosting(true)
            ->minTextChar(0)
            ->maxTextChar(5000)
            ->minVideos(1)
            ->maxPhotos(0)
            ->maxVideos(1)
            ->maxGifs(0)
            ->allowMixingMediaTypes(false)
            ->enableVideoThumb(true);
    }

    public static function postOptions(): SocialProviderPostOptions
    {
        return new YoutubePostOptions;
    }

    public static function externalPostUrl(AccountResource $accountResource): string
    {
        return "https://www.youtube.com/watch?v={$accountResource->pivot->provider_post_id}";
    }

    public static function externalAccountUrl(AccountResource $accountResource): string
    {
        return "https://www.youtube.com/@$accountResource->username";
    }

    public static function mapErrorMessage(string $key): string
    {
        return match ($key) {
            'access_token_expired' => __('mixpost::account.access_token_expired'),
            'upload_failed' => __('mixpost::service.twitter.upload_failed'),
            'request_timeout' => __('mixpost::error.request_timeout'),
            'unknown_error' => __('mixpost::error.unknown_error'),
            'video_not_selected' => __('mixpost::post.video_not_selected'),
            default => $key
        };
    }

    public static function supportAnalytics(): bool
    {
        return true;
    }

    public static function supportPostDeletion(): bool|array
    {
        return true;
    }
}
