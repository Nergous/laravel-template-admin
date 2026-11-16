<?php

namespace App\Http\Resources\Media;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A media library file as shown by lists, pickers and the upload progress in
 * the admin panel (MediaItem in resources/js/admin/types.ts).
 *
 * @mixin Media
 */
class MediaResource extends JsonResource
{
    /**
     * @param  list<string>|null  $usages  Places using the file (MediaUsage::for()); null — not sent
     */
    public function __construct(Media $media, private readonly ?array $usages = null)
    {
        parent::__construct($media);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'filename' => $this->filename,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'type' => $this->type?->value,
            'size' => $this->size,
            'url' => $this->url(),
            'thumb_url' => $this->thumbUrl(),
            'srcset' => $this->srcset(),
            'width' => $this->width,
            'height' => $this->height,
            'focal_x' => $this->focal_x,
            'focal_y' => $this->focal_y,
            'alt' => $this->alt,
            'folder' => $this->folder,
            'created_at' => $this->created_at?->toIso8601String(),
            'usages' => $this->when($this->usages !== null, fn () => $this->usages),
        ];
    }
}
