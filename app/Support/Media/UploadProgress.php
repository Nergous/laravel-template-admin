<?php

namespace App\Support\Media;

use App\Models\Media;
use Illuminate\Database\Eloquent\Collection;

/**
 * Progress of one upload batch (MediaUploads::progress()): the records already
 * saved, the failed files and the files skipped as duplicates.
 */
final class UploadProgress
{
    /**
     * @param  Collection<int, Media>  $items  Records whose current file came from the batch, newest first
     * @param  array<int, list<string>>  $usages  MediaUsage::for() for these records
     * @param  list<array{id: int, name: string, error: string}>  $failed
     * @param  list<array{id: int, name: string, existing_id: int|null}>  $duplicates  existing_id — the record that already holds this content
     */
    public function __construct(
        public readonly Collection $items,
        public readonly array $usages,
        public readonly array $failed,
        public readonly array $duplicates,
    ) {}
}
