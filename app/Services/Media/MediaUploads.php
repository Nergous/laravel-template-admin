<?php

namespace App\Services\Media;

use App\Jobs\UploadMedia;
use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\User;
use App\Support\Media\UploadBatch;
use App\Support\Media\UploadProgress;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Media library uploads: new files and replacements are queued (the
 * UploadMedia job) under one batch id per request, and the progress poll reads
 * the batch back: the saved records and the log entries about failures and duplicates.
 */
class MediaUploads
{
    /** Records returned per poll; a batch holds at most UploadRules::MAX_FILES. */
    private const ITEMS_LIMIT = 50;

    public function __construct(private readonly MediaUsage $usage) {}

    /**
     * Queues new files into a folder (null — the library root).
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function queue(array $files, User $actor, ?string $folder = null): UploadBatch
    {
        $batch = (string) Str::uuid();

        foreach ($files as $file) {
            $this->dispatch($file, $actor, $batch, folder: $folder);
        }

        return new UploadBatch($batch, count($files));
    }

    /**
     * Queues a new file for an existing record. The record keeps its id and
     * display name; the old files are deleted once the new ones are stored.
     */
    public function queueReplacement(Media $media, UploadedFile $file, User $actor): UploadBatch
    {
        $batch = (string) Str::uuid();
        $this->dispatch($file, $actor, $batch, replace: $media);

        return new UploadBatch($batch, 1);
    }

    /** Batch progress; only the viewer's own failures and duplicates count. */
    public function progress(string $batch, User $viewer): UploadProgress
    {
        $items = Media::where('upload_batch', $batch)
            ->orderByDesc('id')
            ->limit(self::ITEMS_LIMIT)
            ->get();
        $issues = $this->issues($batch, $viewer);

        return new UploadProgress(
            $items,
            $this->usage->for($items),
            $issues->where('action', MediaUploadProcessor::ACTION_FAILED)->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'name' => $log->subject_label ?: 'Файл',
                'error' => (string) ($log->changes['error'][1] ?? 'Не удалось обработать файл'),
            ])->values()->all(),
            $issues->where('action', MediaUploadProcessor::ACTION_DUPLICATE)->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'name' => $log->subject_label ?: 'Файл',
                'existing_id' => $log->subject_id,
            ])->values()->all(),
        );
    }

    /** Puts the file into the temporary directory and queues an UploadMedia job. */
    private function dispatch(UploadedFile $file, User $actor, string $batch, ?string $folder = null, ?Media $replace = null): void
    {
        UploadMedia::dispatch(
            $file->store(MediaStorage::TEMP_DIRECTORY, MediaStorage::TEMP_DISK),
            $file->getClientOriginalName(),
            uploaderId: $actor->id,
            replaceMediaId: $replace?->id,
            batch: $batch,
            folder: $folder,
        );
    }

    /** @return Collection<int, ActivityLog> Batch entries about failures and duplicates */
    private function issues(string $batch, User $viewer): Collection
    {
        // The batch id lives in the log entry changes; the user's recent entries are
        // few, so they are filtered here, without a DBMS-specific JSON query.
        return ActivityLog::query()
            ->whereIn('action', [MediaUploadProcessor::ACTION_FAILED, MediaUploadProcessor::ACTION_DUPLICATE])
            ->where('user_id', $viewer->id)
            ->where('created_at', '>=', now()->subDay())
            ->orderBy('id')
            ->limit(500)
            ->get(['id', 'action', 'subject_id', 'subject_label', 'changes'])
            ->filter(fn (ActivityLog $log) => ($log->changes['batch'][1] ?? null) === $batch)
            ->values()
            ->toBase();
    }
}
