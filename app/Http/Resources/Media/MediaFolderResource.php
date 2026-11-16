<?php

namespace App\Http\Resources\Media;

use App\Models\MediaFolder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A media library folder with a summary of its content (a MediaLibrary::folders() row).
 * count and subfolders are what lies directly in the folder, total and size the whole subtree.
 *
 * @property array{folder: MediaFolder, count: int, subfolders: int, total: int, size: int} $resource
 */
class MediaFolderResource extends JsonResource
{
    /** @return array{name: string, count: int, subfolders: int, total: int, size: int, created_at: ?string} */
    public function toArray(Request $request): array
    {
        $folder = $this->resource['folder'];

        return [
            'name' => $folder->name,
            'count' => $this->resource['count'],
            'subfolders' => $this->resource['subfolders'],
            'total' => $this->resource['total'],
            'size' => $this->resource['size'],
            'created_at' => $folder->created_at?->toIso8601String(),
        ];
    }
}
