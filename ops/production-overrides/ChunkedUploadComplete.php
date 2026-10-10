<?php

namespace Inovector\Mixpost\Http\Base\Requests\Workspace\Media;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Inovector\Mixpost\Abstracts\Image;
use Inovector\Mixpost\Concerns\UsesMediaFileDataRules;
use Inovector\Mixpost\Concerns\UsesMediaFolder;
use Inovector\Mixpost\Concerns\UsesMediaPath;
use Inovector\Mixpost\Enums\Capability;
use Inovector\Mixpost\Exceptions\ChunkedUploadSessionNotFound;
use Inovector\Mixpost\Facades\Access;
use Inovector\Mixpost\Facades\WorkspaceManager;
use Inovector\Mixpost\Jobs\OptimizeSocialVideoMediaJob;
use Inovector\Mixpost\MediaConversions\MediaImageResizerConversion;
use Inovector\Mixpost\MediaConversions\MediaVideoThumbConversion;
use Inovector\Mixpost\Models\Media;
use Inovector\Mixpost\Support\LocalChunkedUpload as ChunkedUpload;
use Inovector\Mixpost\Support\LocalMediaStaging;
use Inovector\Mixpost\Support\MediaUploader;

class ChunkedUploadComplete extends FormRequest
{
    use UsesMediaFileDataRules;
    use UsesMediaFolder;
    use UsesMediaPath;

    public function rules(): array
    {
        return array_merge($this->mediaFileDataRules(), $this->folderRules());
    }

    public function handle(): Media
    {
        $chunkedUpload = new ChunkedUpload;
        $uploadUuid = $this->route('uploadUuid');

        Access::check(Capability::UploadMedia, WorkspaceManager::current())->validate();
        try {
            return $chunkedUpload->completeAndStore($uploadUuid, function ($file, $localUuid): Media {
                return $this->storeSource($file, $localUuid);
            });
        } catch (ChunkedUploadSessionNotFound $e) {
            throw ValidationException::withMessages(['upload_session' => ['Upload session not found.']]);
        }
    }

    private function storeSource($file, ?string $localUuid): Media
    {
        $uploader = MediaUploader::fromSource($file)
            ->path(self::mediaWorkspacePathWithDateSubpath())
            ->conversions([
                MediaImageResizerConversion::name('thumb')->width(Image::MEDIUM_WIDTH)->height(Image::MEDIUM_HEIGHT),
                MediaVideoThumbConversion::name('thumb')->atSecond(5),
            ])
            ->data($this->extractFileData())
            ->folder($this->resolveFolderId())
            ->deferVideoConversion();

        if ($localUuid !== null && LocalMediaStaging::enabled()) { return LocalMediaStaging::store($uploader, $localUuid); }
        $media = $uploader->uploadAndInsert();

        if ($media->isVideo()) {
            OptimizeSocialVideoMediaJob::dispatch($media->id);
        }

        return $media;
    }
}
