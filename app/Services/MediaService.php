<?php

namespace App\Services;

use App\Jobs\UploadMedia;
use App\Models\Media;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Media library orchestration: queueing uploads and deleting records along with
 * their storage files.
 *
 * Extracted from the controller because deletion is a multi-step operation
 * (a DB transaction + non-transactional file cleanup), not trivial CRUD.
 * The "when a service, when a controller" boundary is described in CLAUDE.md.
 */
class MediaService
{
    /**
     * Queues each file for processing (the UploadMedia job).
     *
     * @param  array<int, UploadedFile>  $files
     * @return int How many files were queued
     */
    public function queue(array $files, ?int $userId): int
    {
        foreach ($files as $file) {
            $tempPath = $file->store('temp', 'local');
            UploadMedia::dispatch($tempPath, $file->getClientOriginalName(), $userId);
        }

        return count($files);
    }

    /**
     * Deletes a single record and its associated files (original + thumbnail).
     */
    public function delete(Media $media): void
    {
        $media->delete();
        $media->deleteFiles();
    }

    /**
     * Bulk delete: records in a transaction; files are removed after commit,
     * best-effort (storage is not transactional, deleteFiles tolerates a missing file).
     *
     * @param  array<int, int>  $ids
     * @return int How many records were deleted
     */
    public function bulkDelete(array $ids): int
    {
        /** @var Collection<int, Media> $medias */
        $medias = Media::whereIn('id', $ids)->get();

        DB::transaction(function () use ($medias) {
            foreach ($medias as $media) {
                $media->delete();
            }
        });

        foreach ($medias as $media) {
            $media->deleteFiles();
        }

        return $medias->count();
    }
}
