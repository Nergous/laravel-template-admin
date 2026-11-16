<?php

namespace App\Http\Controllers\Admin\Content\Media;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\Media\PollMediaUploadRequest;
use App\Http\Requests\Content\Media\ReplaceMediaRequest;
use App\Http\Requests\Content\Media\UploadMediaRequest;
use App\Http\Resources\Media\MediaUploadProgressResource;
use App\Models\Media;
use App\Services\Media\MediaUploads;
use Illuminate\Http\JsonResponse;

/**
 * Media library uploads: new files, replacing the file of an existing record
 * and the progress poll of both (MediaUploads).
 */
class MediaUploadController extends Controller
{
    public function __construct(private readonly MediaUploads $uploads) {}

    /** Queues files into the given folder (without one — the library root): { queued, batch }. */
    public function store(UploadMediaRequest $request): JsonResponse
    {
        $batch = $this->uploads->queue(
            $request->file('media', []),
            $request->user(),
            $request->validated('folder'),
        );

        return response()->json($batch->toArray());
    }

    /** Queues a new file for a record; after processing its URL changes: { queued: 1, batch }. */
    public function replace(ReplaceMediaRequest $request, Media $media): JsonResponse
    {
        $batch = $this->uploads->queueReplacement($media, $request->file('file'), $request->user());

        return response()->json($batch->toArray());
    }

    /** Progress of one store() or replace() request by batch id. */
    public function poll(PollMediaUploadRequest $request): JsonResponse
    {
        $progress = $this->uploads->progress($request->validated('batch'), $request->user());

        return response()->json((new MediaUploadProgressResource($progress))->resolve($request));
    }
}
