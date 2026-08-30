<?php

namespace Inovector\Mixpost\Http\Base\Requests\Workspace\Post;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Inovector\Mixpost\Contracts\SocialProvider;
use Inovector\Mixpost\Facades\SocialProviderManager;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Support\PeachyPostVersionContent;

abstract class PostFormRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = [
            'accounts' => ['array'],
            'accounts.*' => ['integer', WorkspaceManager::existsRule('mixpost_accounts', 'id')],
            'tags' => ['array'],
            'tags.*' => ['integer', WorkspaceManager::existsRule('mixpost_tags', 'id')],
            'date' => ['nullable', 'date', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
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

    protected function inputVersions(): array
    {
        $providers = SocialProviderManager::providers();

        return Arr::map($this->input('versions', []), function ($version, $index) use ($providers) {
            $content = Arr::map($version['content'] ?? [], function ($content) {
                return [
                    'body' => $content['body'] ?? '',
                    'media' => $content['media'] ?? [],
                    'url' => $content['url'] ?? null,
                    'video_thumbs' => $content['video_thumbs'] ?? [],
                ];
            });

            return [
                'account_id' => $index === 0 ? 0 : $version['account_id'] ?? 0,
                'is_original' => $index === 0,
                'content' => PeachyPostVersionContent::withoutEmptyAdditionalItems($content),
                'options' => Arr::map(Arr::only($version['options'] ?? [], array_keys($providers)), function ($options, $keyProvider) use ($providers) {
                    /** @var SocialProvider $provider */
                    $provider = $providers[$keyProvider] ?? null;

                    if (! $provider) {
                        return [];
                    }

                    return $provider::postOptions()->map($options);
                }),
            ];
        });
    }
}
