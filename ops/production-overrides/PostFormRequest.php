<?php

namespace Inovector\Mixpost\Http\Base\Requests\Workspace\Post;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inovector\Mixpost\Actions\Post\ShiftPostSchedule;
use Inovector\Mixpost\Actions\Post\SyncPostSchedule;
use Inovector\Mixpost\Contracts\SocialProvider;
use Inovector\Mixpost\Enums\PostStatus;
use Inovector\Mixpost\Facades\SocialProviderManager;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Models\Mention;
use Inovector\Mixpost\Models\Post;
use Inovector\Mixpost\Support\PostMentions;
use Inovector\Mixpost\Support\PeachyPostVersionContent;
use Inovector\Mixpost\Util;

abstract class PostFormRequest extends FormRequest
{
    public function rules(): array
    {
        return array_merge(
            $this->accountRules(),
            $this->tagRules(),
            $this->scheduleRules(),
            $this->versionRules(),
        );
    }

    protected function accountRules(): array
    {
        return [
            'accounts' => ['array'],
            'accounts.*' => ['integer', WorkspaceManager::existsRule('mixpost_accounts', 'id')],
        ];
    }

    protected function tagRules(): array
    {
        return [
            'tags' => ['array'],
            'tags.*' => ['integer', WorkspaceManager::existsRule('mixpost_tags', 'id')],
        ];
    }

    protected function scheduleRules(): array
    {
        return [
            'date' => ['nullable', 'date', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
            'accounts_schedule' => ['sometimes', 'array'],
            'accounts_schedule.*.account_id' => ['required', 'integer', WorkspaceManager::existsRule('mixpost_accounts', 'id')],
            'accounts_schedule.*.date' => ['required', 'date', 'date_format:Y-m-d'],
            'accounts_schedule.*.time' => ['required', 'date_format:H:i'],
        ];
    }

    /**
     * A request that states the departures writes them; one that does not is moving the post as a
     * whole, and its accounts keep the distances they were given.
     */
    protected function applySchedule(Post $post, ?Carbon $anchor, ?PostStatus $status = null, ?Carbon $from = null): ?Carbon
    {
        if (! $this->has('accounts_schedule')) {
            return app(ShiftPostSchedule::class)($post, $anchor, $status, $from);
        }

        return app(SyncPostSchedule::class)($post, $this->accountDepartures($post), $anchor, $status);
    }

    /**
     * Keyed by account id and in UTC. A departure belongs to a post's own account, so one named for
     * an account the post does not carry is dropped rather than written to nobody.
     */
    protected function accountDepartures(Post $post): array
    {
        $accountIds = $post->accounts->pluck('id')->flip();

        $departures = [];

        foreach ($this->input('accounts_schedule', []) as $entry) {
            $accountId = (int) Arr::get($entry, 'account_id');

            if (! $accountIds->has($accountId)) {
                continue;
            }

            $departures[$accountId] = Util::convertTimeToUTC(Arr::get($entry, 'date').' '.Arr::get($entry, 'time'), $this->inputTimezone());
        }

        return $departures;
    }

    protected function validateAccountDepartures($validator): void
    {
        foreach ($this->input('accounts_schedule', []) as $index => $entry) {
            $departure = Util::convertTimeToUTC(Arr::get($entry, 'date').' '.Arr::get($entry, 'time'), $this->inputTimezone());

            if ($departure->gte(Carbon::now('UTC')->startOfMinute())) {
                continue;
            }

            $validator->errors()->add("accounts_schedule.$index.time", __('mixpost::post.past_date'));
        }
    }

    protected function versionRules(): array
    {
        $rules = [
            'versions' => ['required', 'array', 'min:1'],
            'versions.*.account_id' => ['required', 'int', function ($attribute, $value, $fail) {
                if ($value != 0 &&
                    ! WorkspaceManager::existsRule('mixpost_accounts', 'id')->unless($value, function ($val) {
                        return $val != 0;
                    })) {
                    $fail('The selected account id is invalid.');
                }
            }],
            'versions.*.is_original' => ['required', 'boolean'],
            'versions.*.synced_with' => ['nullable', 'integer', 'min:0'],
            'versions.*.content' => ['required', 'array', 'min:1'],
            'versions.*.content.*.body' => ['nullable', 'string'],
            'versions.*.content.*.url' => ['nullable', 'string'],
            'versions.*.content.*.media' => ['array'],
            'versions.*.content.*.media.*' => ['integer', WorkspaceManager::existsRule('mixpost_media', 'id')],
            'versions.*.content.*.video_thumbs' => ['array'],
            'versions.*.content.*.video_thumbs.*.media_id' => [
                'integer',
                Rule::requiredIf(! empty($this->input('versions.*.content.*.video_thumbs.*'))),
                WorkspaceManager::existsRule('mixpost_media', 'id')->whereIn('mime_type', ['video/mp4', 'video/x-m4v']),
            ],
            'versions.*.content.*.video_thumbs.*.thumb_id' => [
                'integer',
                Rule::requiredIf(! empty($this->input('versions.*.content.*.video_thumbs.*'))),
                WorkspaceManager::existsRule('mixpost_media', 'id')->whereIn('mime_type', ['image/jpg', 'image/jpeg', 'image/png']),
            ],
            'versions.*.options' => ['sometimes', 'array'],
        ];

        $providers = SocialProviderManager::providers();

        foreach ($this->input('versions', []) as $index => $version) {
            if (empty($version['options'])) {
                continue;
            }

            $options = Arr::only($version['options'], array_keys($providers));

            foreach ($options as $key => $value) {
                /** @var SocialProvider $provider */
                $provider = $providers[$key] ?? null;

                if (! $provider) {
                    continue;
                }

                foreach ($provider::postOptions()->rules($this) as $option => $optionRules) {
                    $rules["versions.{$index}.options.{$key}.{$option}"] = $optionRules;
                }
            }
        }

        return $rules;
    }

    protected function scheduledAt(): ?string
    {
        return $this->input('date') && $this->input('time') ? "{$this->input('date')} {$this->input('time')}" : null;
    }

    protected function scheduledAtInUtc(): ?Carbon
    {
        return $this->scheduledAt() ? Util::convertTimeToUTC($this->scheduledAt(), $this->inputTimezone()) : null;
    }

    /**
     * The timezone the request's dates and times are written in. The dashboard always writes them in
     * the user's own, which is what null falls back to; the public API lets a caller name another.
     */
    protected function inputTimezone(): ?string
    {
        return null;
    }

    protected function inputVersions(): array
    {
        $mentions = $this->ownedMentions();
        $mentionIds = array_keys($mentions);

        Mention::touchUsage($mentionIds);

        return Arr::map($this->input('versions', []), function ($version, $index) use ($mentions, $mentionIds) {
            $identity = $this->versionIdentity($version, $index);

            return array_merge($identity, [
                'synced_with' => $this->syncedWith($version, $identity['account_id']),
                'content' => PeachyPostVersionContent::withoutEmptyAdditionalItems(Arr::map($version['content'] ?? [], function ($content) use ($mentions, $mentionIds) {
                    $body = PostMentions::expandShorthand($content['body'] ?? '', $mentions);

                    return [
                        'body' => PostMentions::normalize($body, $mentionIds),
                        'media' => $content['media'] ?? [],
                        'url' => $content['url'] ?? null,
                        'video_thumbs' => $content['video_thumbs'] ?? [],
                    ];
                })),
                'options' => static::mapVersionOptions($version['options'] ?? []),
            ]);
        });
    }

    /**
     * The mentions referenced across every version that this workspace actually owns, as id => name.
     *
     * A body arrives holding nothing but mention ids — as nodes from the composer, or as
     * `{{mention:id}}` from an API client — so they are resolved here once. Anything unrecognised
     * is flattened back to plain text rather than rejecting the whole save.
     *
     * @return array<int, string>
     */
    protected function ownedMentions(): array
    {
        $ids = [];

        foreach ($this->input('versions', []) as $version) {
            foreach ($version['content'] ?? [] as $content) {
                $body = $content['body'] ?? '';

                $ids = array_merge($ids, PostMentions::ids($body), PostMentions::shorthandIds($body));
            }
        }

        if (empty($ids)) {
            return [];
        }

        return Mention::whereIn('id', array_unique($ids))->pluck('name', 'id')->all();
    }

    /**
     * Keeps the options of the providers there are, each in the shape its provider stores them.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, array<string, mixed>>
     */
    public static function mapVersionOptions(array $options): array
    {
        $providers = SocialProviderManager::providers();

        return Arr::map(Arr::only($options, array_keys($providers)), function ($providerOptions, $keyProvider) use ($providers) {
            /** @var SocialProvider $provider */
            $provider = $providers[$keyProvider];

            return $provider::postOptions()->map(is_array($providerOptions) ? $providerOptions : []);
        });
    }

    // The original is what versions follow, never a follower itself, and no version follows itself.
    protected function syncedWith(array $version, int $accountId): ?int
    {
        if (! isset($version['synced_with']) || $accountId === 0) {
            return null;
        }

        $syncedWith = (int) $version['synced_with'];

        return $syncedWith === $accountId ? null : $syncedWith;
    }

    // A request that states the whole post keeps the original first, whatever the payload claims.
    protected function versionIdentity(array $version, int $index): array
    {
        return [
            'account_id' => $index === 0 ? 0 : $version['account_id'] ?? 0,
            'is_original' => $index === 0,
        ];
    }
}
