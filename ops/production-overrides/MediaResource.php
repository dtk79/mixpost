<?php

namespace Inovector\Mixpost\Http\Base\Resources;

use Illuminate\Http\Resources\Json\JsonResource;
use Inovector\Mixpost\Concerns\ExposesMediaFolder;
use Inovector\Mixpost\Concerns\ResourceHasParameters;

class MediaResource extends JsonResource
{
    use ExposesMediaFolder;
    use ResourceHasParameters;

    public static $wrap = null;

    public function fields()
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'mime_type' => $this->mime_type,
            'type' => $this->type(),
            'url' => $this->getUrl(),
            'thumb_url' => $this->isImageGif() ? $this->getUrl() : $this->getThumbUrl(),
            'video_custom_thumb_url' => $this->additionalFields['video_custom_thumb_url'] ?? null, // This key is used to resolve the custom video thumbnail URL
            'is_video' => $this->isVideo(),
            'is_processing' => $this->isProcessing() || ! \Inovector\Mixpost\Support\LocalMediaStaging::ready($this->resource),
            'storage_state' => $this->resource->data['peachy_storage']['state'] ?? 'ready',
            // Read from what was already stored: measuring an image means opening the file, and a
            // library page asks for a hundred of them at a time. They let a tile take up its final
            // height before the image arrives.
            'width' => $this->resource->data['width'] ?? null,
            'height' => $this->resource->data['height'] ?? null,
            // Kept when the video was stored; null for an image, or a video not read yet.
            'duration_ms' => $this->resource->data['duration_ms'] ?? null,
            'usage' => ($this->usage ?? null)?->value, // Only loaded by EagerLoadMediaUsage
            'is_favorite' => $this->is_favorite ?? null, // Only loaded by EagerLoadMediaFavorites
            'folder' => $this->folderFields(),
            'source_url' => $this->source_url ?? null,
            'credit_url' => $this->credit_url ?? null,
            'author' => $this->author ?? null,
            'source' => $this->source ?? null,
            'alt_text' => $this->alt_text ?? null,
            'download_data' => $this->download_data ?? null,
            'adobe_express_doc_id' => $this->adobe_express_doc_id ?? null,
            // Stock and GIF results are modelled but never stored, so they have no timestamp.
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
