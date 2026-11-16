<?php

namespace App\Http\Resources\Media;

use App\Models\Media;
use App\Support\Media\UploadProgress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Upload progress for the poll (PollResult in
 * resources/js/admin/composables/useMediaUpload.ts).
 *
 * @mixin UploadProgress
 */
class MediaUploadProgressResource extends JsonResource
{
    public function __construct(UploadProgress $progress)
    {
        parent::__construct($progress);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $usages = $this->usages;

        return [
            'items' => $this->items
                ->map(fn (Media $item) => (new MediaResource($item, $usages[$item->id] ?? []))->resolve($request))
                ->values()
                ->all(),
            'failed' => $this->failed,
            'duplicates' => $this->duplicates,
        ];
    }
}
