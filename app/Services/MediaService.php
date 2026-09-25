<?php

namespace App\Services;

use App\Jobs\UploadMedia;
use App\Models\ActivityLog;
use App\Models\Media;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Media library orchestration: queueing uploads and deleting records along with
 * their storage files.
 *
 * Extracted from the controller because deletion is a multi-step operation
 * (a DB transaction + non-transactional file cleanup), not trivial CRUD.
 */
class MediaService
{
    /**
     * Queues each file for processing (the UploadMedia job).
     *
     * @param  array<int, UploadedFile>  $files
     * @param  string|null  $batch  Upload request id; the progress poll finds the results by it
     * @return int How many files were queued
     */
    public function queue(array $files, ?int $userId, ?string $batch = null): int
    {
        foreach ($files as $file) {
            $tempPath = $file->store('temp', 'local');
            UploadMedia::dispatch($tempPath, $file->getClientOriginalName(), $userId, null, $batch);
        }

        return count($files);
    }

    /**
     * Queues a new file for an existing record (the UploadMedia job in replace
     * mode updates the row and removes the old files).
     */
    public function queueReplacement(Media $media, UploadedFile $file, ?int $userId, ?string $batch = null): void
    {
        $tempPath = $file->store('temp', 'local');

        UploadMedia::dispatch($tempPath, $file->getClientOriginalName(), $userId, $media->id, $batch);
    }

    /**
     * Crops an image to a normalized area. The result is stored as a new file
     * (with its own thumbnail and responsive copies), the record points at it,
     * and the old files are removed; the change is logged as media_cropped.
     *
     * @param  array{x: float, y: float, width: float, height: float}  $area
     *
     * @throws ValidationException When the file is not an image that can be processed.
     */
    public function crop(Media $media, array $area): Media
    {
        if (! $media->isImage()) {
            throw ValidationException::withMessages(['crop' => 'Обрезать можно только изображения']);
        }

        try {
            ['filename' => $filename, 'variants' => $variants] = ImageOptimizer::crop($media->filename, $area);
        } catch (\RuntimeException $e) {
            report($e);

            throw ValidationException::withMessages(['crop' => $e->getMessage()]);
        }

        $old = new Media(['filename' => $media->filename, 'variants' => $media->variants]);
        $dimensions = ImageOptimizer::dimensions($filename);
        $disk = Storage::disk(Media::diskName());

        try {
            // Without model events: media_cropped below is the single log entry.
            Media::withoutEvents(fn () => $media->forceFill([
                'filename' => $filename,
                'mime_type' => str_ends_with($filename, '.avif') ? 'image/avif' : 'image/webp',
                'size' => $disk->size($filename),
                'width' => $dimensions['width'] ?? null,
                'height' => $dimensions['height'] ?? null,
                'has_thumb' => $disk->exists(ImageOptimizer::thumbPath($filename)),
                'variants' => $variants === [] ? null : $variants,
                'focal_x' => null,
                'focal_y' => null,
                'updated_by' => Auth::id() ?? $media->updated_by,
            ])->save());
        } catch (\Throwable $e) {
            (new Media(['filename' => $filename, 'variants' => $variants]))->deleteFiles();

            throw $e;
        }

        $old->deleteFiles();

        ActivityLog::record($media, 'media_cropped', [
            'crop' => [null, sprintf('x %.3f, y %.3f, %.3f × %.3f', $area['x'], $area['y'], $area['width'], $area['height'])],
            'width' => [null, $media->width],
            'height' => [null, $media->height],
        ]);

        return $media;
    }

    /**
     * Renames a folder (moving every file into the target, which may already
     * exist). One summary log entry instead of one per file.
     *
     * @return int How many files moved
     */
    public function renameFolder(string $from, string $to): int
    {
        if ($from === $to) {
            return 0;
        }

        $count = Media::where('folder', $from)->update(['folder' => $to]);

        if ($count > 0) {
            ActivityLog::record(null, 'folder_renamed', ['folder' => [$from, $to], 'files' => [null, $count]], $from);
        }

        return $count;
    }

    /**
     * Dissolves a folder: its files stay in the library outside any folder.
     *
     * @return int How many files left the folder
     */
    public function clearFolder(string $folder): int
    {
        $count = Media::where('folder', $folder)->update(['folder' => null]);

        if ($count > 0) {
            ActivityLog::record(null, 'folder_cleared', ['folder' => [$folder, null], 'files' => [null, $count]], $folder);
        }

        return $count;
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

    /**
     * Moves records into a folder (null removes them from any folder). Models are
     * updated one by one so LogsActivity records each change; rows already in the
     * target folder are skipped.
     *
     * @param  array<int, int>  $ids
     * @return int How many records actually changed
     */
    public function moveToFolder(array $ids, ?string $folder): int
    {
        return DB::transaction(function () use ($ids, $folder) {
            $changed = 0;

            Media::whereIn('id', $ids)->get()->each(function (Media $media) use ($folder, &$changed) {
                $media->folder = $folder;
                if ($media->isDirty('folder')) {
                    $media->save();
                    $changed++;
                }
            });

            return $changed;
        });
    }
}
