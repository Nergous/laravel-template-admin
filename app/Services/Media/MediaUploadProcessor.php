<?php

namespace App\Services\Media;

use App\Events\ActivityOccurred;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\User;
use App\Services\System\ActivityRecorder;
use App\Support\Media\MediaFiles;
use App\Support\Media\QueuedUpload;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Processes a file from the upload queue (the UploadMedia job): moves the
 * temporary file from the local disk to media/ on the (possibly remote) media
 * disk and creates a Media record with metadata or updates the replaced one.
 *
 * Images are optimized by ImageOptimizer (WebP + thumbnail), other types
 * (video, audio, documents) are copied as is, without a thumbnail.
 *
 * A new file whose content (SHA-256) is already in the library is not stored
 * again: upload_duplicate is logged with a link to the existing record.
 * Records and log entries carry the upload batch id the progress poll reads.
 *
 * A retry is safe: the temporary file is deleted as the last step and only on
 * success, so a retry processes it again, and when the file is already gone it
 * does nothing and creates no duplicate.
 *
 * The processor writes its own log entries on behalf of the uploader
 * explicitly; model create/update entries (LogsActivity) get the uploader via
 * ActivityRecorder::actingAs: a queued job has no signed-in user.
 */
class MediaUploadProcessor
{
    /** Log action: the upload failed after all attempts (read by the progress poll). */
    public const ACTION_FAILED = 'upload_failed';

    /** Log action: a new file was skipped, the library already holds this content. */
    public const ACTION_DUPLICATE = 'upload_duplicate';

    /** Directory of files on the media disk. */
    private const DIRECTORY = 'media';

    public function __construct(
        private readonly ImageOptimizer $optimizer,
        private readonly MediaStorage $storage,
        private readonly MediaReferenceUpdater $references,
        private readonly ActivityRecorder $recorder,
    ) {}

    /**
     * Processes the file on behalf of the uploader: an image is optimized,
     * anything else is copied as is, then the record is created or updated.
     * Saving the record is the last operation that may throw, so once it
     * succeeds there will be no retry.
     */
    public function process(QueuedUpload $upload): void
    {
        $uploader = $this->uploader($upload);

        $this->recorder->actingAs($uploader, fn () => $this->store($upload, $uploader));
    }

    /**
     * Final cleanup after every attempt failed: deletes the temporary file and
     * logs upload_failed on behalf of the uploader (the progress poll picks it up).
     */
    public function fail(QueuedUpload $upload, ?Throwable $e = null): void
    {
        $this->storage->temp()->delete($upload->tempPath);

        $message = trim((string) ($e?->getMessage() ?: 'Не удалось обработать файл'));

        ActivityOccurred::by(
            $this->uploader($upload),
            null,
            self::ACTION_FAILED,
            ['error' => [null, mb_strimwidth($message, 0, 240, '…')], ...($this->batchChanges($upload) ?? [])],
            $upload->originalName ?: basename($upload->tempPath),
        );
    }

    private function store(QueuedUpload $upload, ?User $uploader): void
    {
        $temp = $this->storage->temp();
        $source = $temp->path($upload->tempPath);

        if (! file_exists($source)) {
            return; // the temporary file was handled by a previous attempt
        }

        $hash = hash_file('sha256', $source) ?: null;

        if ($upload->replaceMediaId === null && $hash !== null
            && ($existing = Media::where('content_hash', $hash)->first()) !== null) {
            ActivityOccurred::by($uploader, $existing, self::ACTION_DUPLICATE, $this->batchChanges($upload), $upload->originalName ?: null);
            $temp->delete($upload->tempPath);

            return;
        }

        $mime = mime_content_type($source) ?: null;
        $result = $mime !== null && str_starts_with($mime, 'image/')
            ? $this->optimizer->optimizeFromDisk($upload->tempPath, MediaStorage::TEMP_DISK, self::DIRECTORY)
            : ['filename' => $this->storage->copy(MediaStorage::TEMP_DISK, $upload->tempPath, self::DIRECTORY), 'variants' => []];
        $files = new MediaFiles($result['filename'], $result['variants']);

        $attributes = [
            ...$this->storage->attributes($files->filename, $mime),
            'variants' => $files->variants === [] ? null : $files->variants,
            'upload_batch' => $upload->batch,
            'content_hash' => $hash,
        ];

        // New files appear before the record: if the record (on replacement, the record
        // and the links in one transaction) cannot be saved, the files are deleted so
        // a retry leaves no orphans on the media disk.
        $replaced = null;

        try {
            if ($upload->replaceMediaId !== null) {
                $replaced = DB::transaction(fn () => $this->replaceFile($upload, $files, $attributes, $uploader));
            } else {
                $this->createRecord($upload, $attributes);
            }
        } catch (Throwable $e) {
            $this->storage->delete($files);

            throw $e;
        }

        // Old files are deleted only once the record and the links finally point at
        // the new ones (including after an outer transaction commits).
        if ($replaced !== null) {
            DB::afterCommit(fn () => $this->storage->delete($replaced));
        }

        $temp->delete($upload->tempPath);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createRecord(QueuedUpload $upload, array $attributes): void
    {
        $media = new Media([...$attributes, 'original_name' => $upload->originalName]);

        // The folder may have been deleted while the job waited in the queue.
        if ($upload->folder !== null && MediaFolder::where('name', $upload->folder)->exists()) {
            $media->folder = $upload->folder;
        }

        if ($upload->uploaderId) {
            $media->created_by = $upload->uploaderId;
        }

        $media->save();
    }

    /**
     * Switches an existing record to a new file. The display name stays,
     * LogsActivity logs the change. Links to the old file in texts and settings
     * are rewritten to the new one (media_links_updated). Runs in the caller's
     * transaction: a failed link rewrite rolls the record change back too.
     *
     * @param  array<string, mixed>  $attributes
     * @return MediaFiles|null The record's previous files, to delete after the commit
     */
    private function replaceFile(QueuedUpload $upload, MediaFiles $files, array $attributes, ?User $uploader): ?MediaFiles
    {
        $media = Media::query()->lockForUpdate()->find($upload->replaceMediaId);

        if ($media === null) {
            // The record was deleted while the job waited in the queue: the new files are not needed.
            $this->storage->delete($files);

            return null;
        }

        $old = MediaFiles::of($media);

        // A different picture: the previous focal point does not apply to it.
        $media->fill([...$attributes, 'focal_x' => null, 'focal_y' => null]);
        if ($upload->uploaderId) {
            $media->updated_by = $upload->uploaderId;
        }
        $media->save();

        $links = $this->references->rewrite($old, $media, $uploader);
        if ($links > 0) {
            ActivityOccurred::by($uploader, $media, 'media_links_updated', ['links_updated' => [null, $links]]);
        }

        return $old;
    }

    private function uploader(QueuedUpload $upload): ?User
    {
        return $upload->uploaderId ? User::withTrashed()->find($upload->uploaderId) : null;
    }

    /** @return array{batch: array{0: null, 1: string}}|null Batch id in the log changes format */
    private function batchChanges(QueuedUpload $upload): ?array
    {
        return $upload->batch !== null ? ['batch' => [null, $upload->batch]] : null;
    }
}
