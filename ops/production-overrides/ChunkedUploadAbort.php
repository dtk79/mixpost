<?php

namespace Inovector\Mixpost\Http\Base\Requests\Workspace\Media;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Inovector\Mixpost\Exceptions\ChunkedUploadSessionNotFound;
use Inovector\Mixpost\Support\LocalChunkedUpload as ChunkedUpload;

class ChunkedUploadAbort extends FormRequest
{
    public function handle(): void
    {
        try {
            (new ChunkedUpload)
                ->abort($this->route('uploadUuid'));
        } catch (ChunkedUploadSessionNotFound $e) {
            throw ValidationException::withMessages([
                'upload_session' => ['Upload session not found.'],
            ]);
        }
    }
}
