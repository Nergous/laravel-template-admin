<?php

namespace App\Support\Media;

/**
 * One queued upload request: the batch id the progress poll uses, and how
 * many files were queued.
 */
final class UploadBatch
{
    public function __construct(
        public readonly string $id,
        public readonly int $queued,
    ) {}

    /** @return array{queued: int, batch: string} */
    public function toArray(): array
    {
        return ['queued' => $this->queued, 'batch' => $this->id];
    }
}
