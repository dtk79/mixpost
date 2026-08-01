<?php

namespace Inovector\Mixpost\Http\Base\Requests\Workspace;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rules\File;
use Inovector\Mixpost\Abstracts\Image;
use Inovector\Mixpost\Concerns\UsesFileConfig;
use Inovector\Mixpost\Concerns\UsesMediaFileDataRules;
use Inovector\Mixpost\Concerns\UsesMediaPath;
use Inovector\Mixpost\Enums\Capability;
use Inovector\Mixpost\Enums\FileSizeUnit;
use Inovector\Mixpost\Facades\Access;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Jobs\OptimizeSocialVideoMediaJob;
use Inovector\Mixpost\MediaConversions\MediaImageResizerConversion;
use Inovector\Mixpost\MediaConversions\MediaVideoThumbConversion;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Support\File as FileSupport;
use Inovector\Mixpost\Support\FileSize;
use Inovector\Mixpost\Support\MediaSource;
use Inovector\Mixpost\Support\MediaUploader;

class MediaUploadFile extends FormRequest
{
    use UsesFileConfig;
    use UsesMediaFileDataRules;
    use UsesMediaPath;

    public function rules(): array
    {
        return array_merge([
            'file' => ['required', 'file', function ($attribute, $value, $fail) {
                $max = $this->maxSizeForMimeType(
                    mimeType: $this->getFile()->getMimeType(),
                    unit: FileSizeUnit::KB
                );
                $rules = File::types($this->allowedMimeTypes())->max($max);

                validator(['file' => $value], ['file' => [$rules]])->validate();
            }],
        ], $this->mediaFileDataRules());
    }

    public function handle(): Media
    {
        $source = MediaSource::fromUploadedFile($this->getFile());

        Access::check(Capability::UploadMedia, WorkspaceManager::current())->validate();

        $media = MediaUploader::fromSource($source)
            ->path(self::mediaWorkspacePathWithDateSubpath())
            ->conversions([
                MediaImageResizerConversion::name('thumb')->width(Image::MEDIUM_WIDTH)->height(Image::MEDIUM_HEIGHT),
                MediaVideoThumbConversion::name('thumb')->atSecond(5),
            ])
            ->data($this->extractFileData())
            ->deferVideoConversion()
            ->uploadAndInsert();

        if ($media->isVideo()) {
            OptimizeSocialVideoMediaJob::dispatch($media->id);
        }

        return $media;
    }

    protected function getFile(): UploadedFile
    {
        return $this->file('file');
    }

    public function messages(): array
    {
        if (! $this->file('file')) {
            return [
                'file.required' => __('mixpost::rules.file_required'),
            ];
        }

        $mimeType = $this->getFile()->getMimeType();

        $max = $this->maxSizeForMimeType(
            mimeType: $mimeType,
            unit: FileSizeUnit::KB
        );

        return [
            'file.max' => __('mixpost::rules.file_max_size', ['type' => FileSupport::type($mimeType), 'max' => FileSize::kbToMb($max)]),
        ];
    }
}
