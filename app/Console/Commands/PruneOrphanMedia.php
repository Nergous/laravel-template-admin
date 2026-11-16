<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Support\Media\MediaFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes files no media library record references.
 *
 * Where stray files come from:
 *  - storage/app/temp — uploads whose job never ran or failed before cleanup;
 *  - the media/ folder on the media disk (config('media.disk')) — originals and previews
 *    left after failed jobs or manual database edits.
 *
 * Only files older than --hours (24 by default, at least 1) are deleted: a job
 * writes the new files first and only then switches the record to them (upload,
 * replacement, crop), and one job attempt is limited to 5 minutes.
 * Temporary files of jobs still waiting in the database queue are never deleted,
 * whatever their age. The scheduler runs the command daily (routes/console.php).
 *
 * Usage:
 *   php artisan media:prune-orphans --dry-run
 *   php artisan media:prune-orphans --hours=48
 */
class PruneOrphanMedia extends Command
{
    protected $signature = 'media:prune-orphans
                            {--hours=24 : Не трогать файлы моложе указанного числа часов}
                            {--dry-run : Только показать, что будет удалено}';

    protected $description = 'Удаляет временные и медиафайлы, на которые не ссылается ни одна запись';

    public function handle(): int
    {
        $cutoff = now()->subHours(max(1, (int) $this->option('hours')))->getTimestamp();
        $dryRun = (bool) $this->option('dry-run');

        $queued = $this->queuedTempFiles();
        $temp = $this->prune('local', 'temp', $cutoff, $dryRun, fn (string $file) => isset($queued[$file]));

        $known = [];
        Media::query()->select(['id', 'filename', 'variants'])->lazyById()->each(function (Media $media) use (&$known) {
            foreach (MediaFiles::of($media)->paths() as $path) {
                $known[$path] = true;
            }
        });

        $media = $this->prune(Media::diskName(), 'media', $cutoff, $dryRun, fn (string $file) => isset($known[$file]));

        $verb = $dryRun ? 'Будет удалено' : 'Удалено';
        $this->info("{$verb}: temp — {$temp}, media — {$media}");

        return self::SUCCESS;
    }

    /**
     * Temporary files of jobs waiting in the database queue (in case the queue backs
     * up longer than the age window). For other queue drivers the window is enough.
     *
     * @return array<string, true>
     */
    private function queuedTempFiles(): array
    {
        $connection = (string) config('queue.default');
        $config = (array) config("queue.connections.{$connection}", []);
        if (($config['driver'] ?? null) !== 'database') {
            return [];
        }

        $paths = [];
        DB::connection($config['connection'] ?? null)
            ->table((string) ($config['table'] ?? 'jobs'))
            ->select(['id', 'payload'])
            ->where('payload', 'like', '%UploadMedia%')
            ->lazyById()
            ->each(function (object $job) use (&$paths) {
                $command = (string) (json_decode((string) $job->payload, true)['data']['command'] ?? '');
                if (preg_match_all('~"(temp/[^"]+)"~', $command, $matches)) {
                    foreach ($matches[1] as $path) {
                        $paths[$path] = true;
                    }
                }
            });

        return $paths;
    }

    /**
     * @param  callable(string): bool  $isReferenced
     */
    private function prune(string $diskName, string $directory, int $cutoff, bool $dryRun, callable $isReferenced): int
    {
        $disk = Storage::disk($diskName);
        $count = 0;

        foreach ($disk->allFiles($directory) as $file) {
            if (basename($file) === '.gitignore' || $isReferenced($file) || $disk->lastModified($file) > $cutoff) {
                continue;
            }

            $this->line(($dryRun ? '  ? ' : '  - ')."{$diskName}:{$file}");
            if (! $dryRun) {
                $disk->delete($file);
            }
            $count++;
        }

        return $count;
    }
}
