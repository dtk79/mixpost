<?php

namespace Inovector\Mixpost\Http\Base\Requests\Workspace\Media;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;
use Inovector\Mixpost\Concerns\UsesMediaPath;
use Inovector\Mixpost\Exceptions\ChunkedUploadSessionNotFound;
use Inovector\Mixpost\Support\LocalChunkedUpload as ChunkedUpload;
use Inovector\Mixpost\Support\FileSize;

class ChunkedUploadChunk extends FormRequest
{
    use UsesMediaPath;

    protected $stopOnFirstFailure = true;

    public function rules(): array
    {
        $uploadSession = $this->get('upload_session');
        $maxChunkSizeKb = FileSize::bytesToKb($uploadSession['chunk_size'] ?? 0);

        return [
            'upload_session' => ['required'],
            'chunk' => ['required', 'file', File::default()->max($maxChunkSizeKb)],
            'chunk_index' => ['required', 'integer', 'min:0', 'max:'.(($uploadSession['total_chunks'] ?? 0) - 1)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $uploadSession = $this->getUploadSession();

        if (! $uploadSession) {
            return;
        }

        $this->merge([
            'upload_session' => $uploadSession,
        ]);
    }

    public function handle(): array
    {
        return $this->chunkedUpload()->uploadChunk(
            uploadUuid: $this->route('uploadUuid'),
            chunkIndex: (int) $this->input('chunk_index'),
            chunk: $this->file('chunk')
        );
    }

    protected function getUploadSession(): ?array
    {
        try {
            return $this->chunkedUpload()->getSessionData($this->route('uploadUuid'));
        } catch (ChunkedUploadSessionNotFound) {
            return null;
        }
    }

    private function chunkedUpload(): ChunkedUpload
    {
        return app(ChunkedUpload::class);
    }
}
