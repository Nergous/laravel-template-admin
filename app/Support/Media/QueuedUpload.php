<?php

namespace App\Support\Media;

/**
 * A file queued for upload into the media library (the UploadMedia job).
 */
final class QueuedUpload
{
    /**
     * @param  string  $tempPath  Temporary file on the local disk (e.g. temp/randomname.jpg)
     * @param  string|null  $originalName  Original file name at upload
     * @param  int|null  $uploaderId  Who uploaded it (for created_by; null — console or outside a session)
     * @param  int|null  $replaceMediaId  The record whose file is replaced (null — a new record)
     * @param  string|null  $batch  Upload request id for the progress poll
     * @param  string|null  $folder  Media library folder of the new record (null — outside folders)
     */
    public function __construct(
        public readonly string $tempPath,
        public readonly ?string $originalName = null,
        public readonly ?int $uploaderId = null,
        public readonly ?int $replaceMediaId = null,
        public readonly ?string $batch = null,
        public readonly ?string $folder = null,
    ) {}
}
