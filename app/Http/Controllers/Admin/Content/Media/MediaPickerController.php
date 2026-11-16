<?php

namespace App\Http\Controllers\Admin\Content\Media;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\Media\MediaPickerRequest;
use App\Http\Resources\Media\MediaResource;
use App\Models\Media;
use App\Services\Media\MediaLibrary;
use App\Support\Media\UploadRules;
use Illuminate\Http\JsonResponse;

/**
 * The media library as JSON for picking media on other screens (search, type,
 * pagination). Returns { data: [...], current_page, last_page, upload }.
 */
class MediaPickerController extends Controller
{
    public function __invoke(MediaPickerRequest $request, MediaLibrary $library): JsonResponse
    {
        $media = $library->pickerPage($request->validated('search'), $request->type(), $request->page());

        return response()->json([
            'data' => $media->getCollection()->map(fn (Media $item) => (new MediaResource($item))->resolve($request))->all(),
            'current_page' => $media->currentPage(),
            'last_page' => $media->lastPage(),
            // Upload limits for uploading right from the picker (by the chosen type);
            // null — the user lacks the media.upload permission.
            'upload' => $request->user()->can('media.upload')
                ? UploadRules::toArray($request->type())
                : null,
        ]);
    }
}
