<?php

namespace App\Http\Resources\Media;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A file chosen in an admin form (MediaRef in resources/js/admin/types.ts).
 *
 * @mixin Media
 */
class MediaRefResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type?->value,
            'url' => $this->url(),
            'thumb_url' => $this->thumbUrl(),
            'original_name' => $this->original_name,
            'size' => $this->size,
            'alt' => $this->alt,
        ];
    }

    /** Value of an optional file for a form: a file reference or null. */
    public static function maybe(?Media $media): ?array
    {
        return $media ? (new self($media))->resolve() : null;
    }
}
