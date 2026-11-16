<?php

namespace App\Jobs;

use App\Services\Media\MediaUploadProcessor;
use App\Support\Media\QueuedUpload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Uploads a file into the media library. The job holds the upload parameters
 * and queue settings; the processing itself lives in MediaUploadProcessor.
 *
 * The temporary file is put into storage/app/temp while handling the request
 * (MediaUploads::queue() / queueReplacement()), then the job is dispatched.
 * With the sync queue (the default) it runs inside the request; with another driver a queue:work worker runs it.
 */
class UploadMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Limit of one attempt (s): large images and videos on a remote disk take
     * long to process. Must stay strictly below the queue's retry_after
     * (720 s by default, config/queue.php).
     */
    public int $timeout = 300;

    /** How many times to try processing the file before the job lands in failed_jobs. */
    public int $tries = 3;

    /**
     * Growing pause between attempts (s) in case of a transient disk or DB failure.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * The parameters are described in QueuedUpload.
     */
    public function __construct(
        protected string $tempPath,
        protected ?string $originalName = null,
        protected ?int $uploaderId = null,
        protected ?int $replaceMediaId = null,
        protected ?string $batch = null,
        protected ?string $folder = null,
    ) {}

    public function handle(MediaUploadProcessor $processor): void
    {
        $processor->process($this->upload());
    }

    /** Called by the queue after the last failed attempt. */
    public function failed(?\Throwable $e = null): void
    {
        // The queue calls failed() without dependency injection.
        app(MediaUploadProcessor::class)->fail($this->upload(), $e);
    }

    private function upload(): QueuedUpload
    {
        return new QueuedUpload(
            $this->tempPath,
            $this->originalName,
            $this->uploaderId,
            $this->replaceMediaId,
            $this->batch,
            $this->folder,
        );
    }
}
