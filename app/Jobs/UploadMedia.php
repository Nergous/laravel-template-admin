<?php

namespace App\Jobs;

use App\Models\Media;
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
 * Takes the path to a temporary file, moves it to storage/public/media
 * and creates a Media record with metadata (mime, type, size, original name).
 *
 * Images are optimized via ImageOptimizer (WebP + thumbnail).
 * Other types (video/audio/documents) are stored as-is, without a thumbnail.
 *
 * The temporary file is saved to storage/app/temp while handling the request
 * in AdminMediaController::store(), then this job is queued.
 *
 * Reliability: the job is hardened for the queue ($tries/$timeout/$backoff below).
 * Temp is deleted only after success — a retry reprocesses the file from scratch;
 * row creation is idempotent (if temp is already removed — the job is a no-op, no
 * duplicate). Production worker: queue:work with supervision (see docker-compose.yml).
 *
 * Usage:
 *   $tempPath = $file->store('temp', 'local');
 *   UploadMedia::dispatch($tempPath, $file->getClientOriginalName());
 */
class UploadMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Destination directory on the public disk. */
    private const DIRECTORY = 'media';

    /**
     * Hard limit per attempt (sec). Must be strictly less than the queue
     * retry_after (90s, config/queue.php).
     */
    public int $timeout = 60;

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
     */
    public function __construct(
        protected string $tempPath,
        protected ?string $originalName = null,
        protected ?int $uploaderId = null,
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
        $localDisk = Storage::disk('local');
        $fullSource = $localDisk->path($this->tempPath);

        if (! file_exists($fullSource)) {
            return; // temp already processed/removed — idempotent no-op
        }

        $mime = mime_content_type($fullSource) ?: null;

        if ($mime !== null && str_starts_with($mime, 'image/')) {
            $filename = ImageOptimizer::storeFromDisk(
                sourcePath: $this->tempPath,
                sourceDisk: 'local',
                directory: self::DIRECTORY,
            );
        } else {
            $filename = $this->copyAsIs();
        }

        $size = Storage::disk('public')->exists($filename)
            ? Storage::disk('public')->size($filename)
            : null;

        $thumbPath = ImageOptimizer::thumbPath($filename);
        $hasThumb = $thumbPath !== $filename && Storage::disk('public')->exists($thumbPath);

        $media = new Media([
            'filename' => $filename,
            'original_name' => $this->originalName,
            'mime_type' => $mime,
            'type' => Media::categorize($mime),
            'size' => $size,
            'has_thumb' => $hasThumb,
        ]);

        if ($this->uploaderId) {
            $media->created_by = $this->uploaderId;
        }

        $media->save();

        $localDisk->delete($this->tempPath);
    }

    /**
     * Copies the file to the public disk without processing, preserving the extension.
     *
     * @return string The new file's name relative to the public disk
     */
    private function copyAsIs(): string
    {
        $ext = pathinfo($this->tempPath, PATHINFO_EXTENSION);

        $filename = self::DIRECTORY.'/'.Str::random(32).($ext ? '.'.$ext : '');

        $stream = Storage::disk('local')->readStream($this->tempPath);
        Storage::disk('public')->writeStream($filename, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $filename;
    }

    /**
     * Final cleanup of the temp file after all retries have failed.
     */
    public function failed(?\Throwable $e = null): void
    {
        Storage::disk('local')->delete($this->tempPath);
    }
}
