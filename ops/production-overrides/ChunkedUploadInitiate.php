<?php

namespace Inovector\Mixpost\Http\Base\Requests\Workspace\Media;

use Illuminate\Foundation\Http\FormRequest;
use Inovector\Mixpost\Concerns\UsesFileConfig;
use Inovector\Mixpost\Enums\Capability;
use Inovector\Mixpost\Enums\FileSizeUnit;
use Inovector\Mixpost\Facades\Access;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Support\LocalChunkedUpload as ChunkedUpload;
use Inovector\Mixpost\Support\File;
use Inovector\Mixpost\Support\FileSize;

class ChunkedUploadInitiate extends FormRequest
{
    use UsesFileConfig;

    public function rules(): array
    {
        return [
            'filename' => ['required', 'string', 'max:255'],
            'mime_type' => ['required', 'string', function ($attribute, $value, $fail) {
                if (! in_array($value, $this->allowedMimeTypes(), true)) {
                    $fail('The selected mime type is invalid.');
                }
            }],
            'total_size' => ['required', 'integer', 'min:1', function ($attribute, $value, $fail) {
                // `total_size` received in bytes
                $mimeType = $this->input('mime_type');
                $max = $this->maxSizeForMimeType(
                    mimeType: $mimeType,
                    unit: FileSizeUnit::BYTES
                );

                if ($value > $max) {
                    $fail(__('mixpost::rules.file_max_size', ['type' => File::type($mimeType), 'max' => FileSize::bytesToMb($max)]));
                }
            }],
        ];
    }

    public function handle(): array
    {
        Access::check(Capability::UploadMedia, WorkspaceManager::current())->validate();

        return (new ChunkedUpload)->initiate(
            filename: $this->input('filename'),
            mimeType: $this->input('mime_type'),
            totalSize: (int) $this->input('total_size'),
        );
    }
}
