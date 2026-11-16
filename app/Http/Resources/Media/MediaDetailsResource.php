<?php

namespace App\Http\Resources\Media;

use App\Models\Media;
use Illuminate\Http\Request;

/**
 * File details for the media library drawer (MediaDetails in
 * resources/js/admin/types.ts): who uploaded and changed it, dates, image
 * dimensions and every place using the file. Expects creator and editor loaded.
 *
 * @mixin Media
 */
class MediaDetailsResource extends MediaResource
{
    /**
     * @param  list<array{label: string, title: ?string, url: ?string}>  $places  MediaUsage::places()
     * @param  array{width: int, height: int}|null  $dimensions  MediaService::dimensions()
     */
    public function __construct(
        Media $media,
        private readonly array $places,
        private readonly ?array $dimensions,
    ) {
        parent::__construct($media, array_values(array_unique(array_column($places, 'label'))));
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'dimensions' => $this->dimensions,
            'creator_name' => $this->creator?->name,
            'editor_name' => $this->editor?->name,
            'updated_at' => $this->updated_at?->toIso8601String(),
            'places' => $this->places,
        ];
    }
}
