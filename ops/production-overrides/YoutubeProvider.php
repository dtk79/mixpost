<?php

namespace Inovector\Mixpost\SocialProviders\Google;

use Closure;
use Inovector\Mixpost\Abstracts\SocialProvider;
use Inovector\Mixpost\Concerns\OAuth\RefreshesAccessToken;
use Inovector\Mixpost\Contracts\AccountResource;
use Inovector\Mixpost\Contracts\SocialProviderPostOptions;
use Inovector\Mixpost\Enums\MediaType;
use Inovector\Mixpost\Services\GoogleService;
use Inovector\Mixpost\Jobs\DispatchYoutubeAudienceSnapshotsJob;
use Inovector\Mixpost\SocialProviders\Google\Concerns\ManagesOAuth;
use Inovector\Mixpost\SocialProviders\Google\Concerns\ManagesYoutubeAccountDeletion;
use Inovector\Mixpost\SocialProviders\Google\Concerns\ManagesYoutubeJobs;
use Inovector\Mixpost\SocialProviders\Google\Concerns\ManagesYoutubeResources;
use Inovector\Mixpost\SocialProviders\Google\Concerns\UsesResponseBuilder;
use Inovector\Mixpost\SocialProviders\Google\Support\YoutubePostOptions;
use Inovector\Mixpost\Support\PostRequirement;
use Inovector\Mixpost\Support\SocialProviderMentionConfigs;
use Inovector\Mixpost\Support\SocialProviderPostConfigs;
use Inovector\Mixpost\Support\SocialProviderPostRequirements;

class YoutubeProvider extends SocialProvider
{
    public bool $onlyUserAccount = false;

    public array $callbackResponseKeys = ['code'];

    use ManagesOAuth;
    use ManagesYoutubeAccountDeletion;
    use ManagesYoutubeJobs {
        lowPriorityJobs as protected upstreamLowPriorityJobs;
    }
    use ManagesYoutubeResources;
    use RefreshesAccessToken;
    use UsesResponseBuilder;

    public static function lowPriorityJobs(): array
    {
        return [...self::upstreamLowPriorityJobs(), DispatchYoutubeAudienceSnapshotsJob::class];
    }

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
            ->enableVideoThumb(true);
    }

    public static function mentionConfigs(): SocialProviderMentionConfigs
    {
        return SocialProviderMentionConfigs::make()->supported(false);
    }

    public static function postOptions(): SocialProviderPostOptions
    {
        return new YoutubePostOptions;
    }

    public static function postRequirements(Closure $getAccountData): SocialProviderPostRequirements
    {
        return SocialProviderPostRequirements::make(
            PostRequirement::media([MediaType::VIDEO], message: __('mixpost::post.rules.video_required')),
            PostRequirement::maxText(5000),
            PostRequirement::maxMedia(photos: 0, videos: 1, gifs: 0),
            PostRequirement::requiredOptions(['title'], __('mixpost::post.rules.title_required')),
            PostRequirement::optionMaxLength('title', 100, __('mixpost::post.rules.title_too_long', ['count' => 100])),
        );
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
            'video_not_selected' => __('mixpost::post.video_not_selected'),
            default => parent::mapErrorMessage($key),
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
