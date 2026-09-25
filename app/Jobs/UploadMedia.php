<?php

namespace App\Jobs;

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\User;
use App\Services\ImageOptimizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Job for asynchronously uploading a file to the media library.
 *
 * Takes the path to a temporary file on the local disk, moves it to media/ on
 * the media disk (config('media.disk'), possibly remote) and creates a Media
 * record with metadata (mime, type, size, dimensions, original name).
 *
 * Images are optimized via ImageOptimizer (WebP + thumbnail).
 * Other types (video/audio/documents) are stored as-is, without a thumbnail.
 *
 * A new upload whose content (SHA-256) already exists in the library is not
 * stored again: the job logs upload_duplicate pointing at the existing record.
 * Rows and log entries carry the upload batch id, which the progress poll uses.
 *
 * The temporary file is saved to storage/app/temp while handling the request
 * in AdminMediaController::store(), then this job is queued.
 *
 * Reliability: the job is hardened for the queue ($tries/$timeout/$backoff below).
 * Temp is deleted only after success — a retry reprocesses the file from scratch;
 * row creation is idempotent (if temp is already removed — the job is a no-op, no
 * duplicate). Production worker: queue:work with supervision (see docker-compose.yml).
 *
 * Activity: the job runs under ActivityLog::actingAs(uploader), so the created /
 * updated entries name the admin who uploaded the file; after the last failed
 * attempt failed() writes an upload_failed entry that the upload poll reports.
 *
 * Usage:
 *   $tempPath = $file->store('temp', 'local');
 *   UploadMedia::dispatch($tempPath, $file->getClientOriginalName());
 */
class UploadMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Destination directory on the media disk. */
    private const DIRECTORY = 'media';

    /**
     * Hard limit per attempt (sec): large images and videos on a remote disk
     * take a while. Must be strictly less than the queue retry_after
     * (330s by default, config/queue.php).
     */
    public int $timeout = 300;

    /**
     * How many times to try processing the file before going to failed_jobs.
     */
    public int $tries = 3;

    /**
     * Increasing pause between retries (sec) — in case of a transient failure
     * (disk/DB unavailability).
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * @param  string  $tempPath  Path to the temporary file relative to the local disk
     *                            (e.g.: temp/randomname.jpg)
     * @param  string|null  $originalName  The file's original name at upload time
     * @param  int|null  $uploaderId  ID of the uploading user (for created_by; null from CLI/outside a session)
     * @param  int|null  $replaceMediaId  Existing record whose file this upload replaces (null — a new record)
     * @param  string|null  $batch  Upload request id (AdminMediaController::store/replace) for progress polling
     */
    public function __construct(
        protected string $tempPath,
        protected ?string $originalName = null,
        protected ?int $uploaderId = null,
        protected ?int $replaceMediaId = null,
        protected ?string $batch = null,
    ) {}

    /**
     * Job execution.
     *
     * 1. Determines the MIME type of the temporary file
     * 2. Image → optimizes it (WebP + thumbnail); otherwise → copies it as-is
     * 3. Creates a Media record with metadata
     * 4. Deletes the temporary file (only after success — see below)
     *
     * temp is deleted as the last step, only on success. On failure temp remains,
     * and a retry reprocesses it from scratch. If temp is already gone (processed
     * by a previous attempt) — the job is a no-op, not creating a duplicate row.
     * Row creation is the last operation that can throw an exception, so a retry
     * after its success is impossible.
     */
    public function handle(): void
    {
        $uploader = $this->uploaderId ? User::withTrashed()->find($this->uploaderId) : null;

        ActivityLog::actingAs($uploader, fn () => $this->process());
    }

    private function process(): void
    {
        $localDisk = Storage::disk('local');
        $fullSource = $localDisk->path($this->tempPath);

        if (! file_exists($fullSource)) {
            return; // temp already processed/removed — idempotent no-op
        }

        $hash = hash_file('sha256', $fullSource) ?: null;

        if ($this->replaceMediaId === null && $hash !== null
            && ($existing = Media::where('content_hash', $hash)->first()) !== null) {
            ActivityLog::record($existing, 'upload_duplicate', $this->batchChanges(), $this->originalName ?: null);
            $localDisk->delete($this->tempPath);

            return;
        }

        $mime = mime_content_type($fullSource) ?: null;
        $variants = [];

        if ($mime !== null && str_starts_with($mime, 'image/')) {
            ['filename' => $filename, 'variants' => $variants] = ImageOptimizer::optimizeFromDisk(
                sourcePath: $this->tempPath,
                sourceDisk: 'local',
                directory: self::DIRECTORY,
            );
        } else {
            $filename = $this->copyAsIs();
        }

        $mediaDisk = Storage::disk(Media::diskName());
        $size = $mediaDisk->exists($filename)
            ? $mediaDisk->size($filename)
            : null;

        $thumbPath = ImageOptimizer::thumbPath($filename);
        $hasThumb = $thumbPath !== $filename && $mediaDisk->exists($thumbPath);
        $dimensions = str_starts_with((string) $mime, 'image/')
            ? ImageOptimizer::dimensions($filename)
            : null;

        $attributes = [
            'filename' => $filename,
            'mime_type' => $mime,
            'type' => Media::categorize($mime),
            'size' => $size,
            'width' => $dimensions['width'] ?? null,
            'height' => $dimensions['height'] ?? null,
            'has_thumb' => $hasThumb,
            'variants' => $variants === [] ? null : $variants,
            'upload_batch' => $this->batch,
            'content_hash' => $hash,
        ];

        // The new files exist before the row does: if the row cannot be saved,
        // remove them so a retry does not leave orphans on the media disk.
        $replaced = null;

        try {
            if ($this->replaceMediaId !== null) {
                $replaced = $this->replaceFile($attributes);
            } else {
                $this->createRecord($attributes);
            }
        } catch (\Throwable $e) {
            (new Media(['filename' => $filename, 'variants' => $attributes['variants']]))->deleteFiles();

            throw $e;
        }

        // Old files go only after the row points at the new ones.
        $replaced?->deleteFiles();

        $localDisk->delete($this->tempPath);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createRecord(array $attributes): void
    {
        $media = new Media([...$attributes, 'original_name' => $this->originalName]);

        if ($this->uploaderId) {
            $media->created_by = $this->uploaderId;
        }

        $media->save();
    }

    /**
     * Points an existing record at the new file. The display name stays; the
     * change is logged by LogsActivity.
     *
     * @param  array<string, mixed>  $attributes
     * @return Media|null A detached model holding the old filename, for file cleanup
     */
    private function replaceFile(array $attributes): ?Media
    {
        $media = Media::find($this->replaceMediaId);

        if ($media === null) {
            // The record was deleted while the job waited — drop the new files.
            (new Media(['filename' => $attributes['filename'], 'variants' => $attributes['variants']]))->deleteFiles();

            return null;
        }

        $old = new Media(['filename' => $media->filename, 'variants' => $media->variants]);

        // A different picture: the old focal point no longer applies.
        $media->fill([...$attributes, 'focal_x' => null, 'focal_y' => null]);
        if ($this->uploaderId) {
            $media->updated_by = $this->uploaderId;
        }
        $media->save();

        return $old;
    }

    /**
     * Copies the file to the media disk without processing, preserving the extension.
     *
     * @return string The new file's name relative to the media disk
     */
    private function copyAsIs(): string
    {
        $ext = pathinfo($this->tempPath, PATHINFO_EXTENSION);

        $filename = self::DIRECTORY.'/'.Str::random(32).($ext ? '.'.$ext : '');

        $stream = Storage::disk('local')->readStream($this->tempPath);
        Storage::disk(Media::diskName())->writeStream($filename, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $filename;
    }

    /**
     * Final cleanup of the temp file after all retries have failed, plus an
     * upload_failed log entry on behalf of the uploader (picked up by the poll).
     */
    public function failed(?\Throwable $e = null): void
    {
        Storage::disk('local')->delete($this->tempPath);

        $uploader = $this->uploaderId ? User::withTrashed()->find($this->uploaderId) : null;
        $message = trim((string) ($e?->getMessage() ?: 'Не удалось обработать файл'));
        $message = mb_strimwidth($message, 0, 240, '…');

        ActivityLog::actingAs($uploader, fn () => ActivityLog::record(
            null,
            'upload_failed',
            ['error' => [null, $message], ...($this->batchChanges() ?? [])],
            $this->originalName ?: basename($this->tempPath),
        ));
    }

    /** @return array{batch: array{0: null, 1: string}}|null The batch id in the log diff format. */
    private function batchChanges(): ?array
    {
        return $this->batch !== null ? ['batch' => [null, $this->batch]] : null;
    }
}
