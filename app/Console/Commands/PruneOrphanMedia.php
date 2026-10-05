<?php

namespace App\Console\Commands;

use App\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Removes files that no media row references.
 *
 * Two sources of orphans:
 *  - storage/app/temp — uploads whose job never ran or crashed before cleanup;
 *  - the media/ directory on the media disk (config('media.disk')) —
 *    originals/thumbnails left after failed jobs or manual database edits.
 *
 * Only files older than --hours (24 by default, at least 1) are touched: a
 * job writes its new files before it points the row at them (an upload, a
 * replacement, a crop), and a job attempt is capped at 5 minutes. Temp files
 * of jobs still waiting in a database queue are kept at any age. Runs daily
 * from the scheduler (routes/console.php).
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
            foreach ($media->storedPaths() as $path) {
                $known[$path] = true;
            }
        });

        $media = $this->prune(Media::diskName(), 'media', $cutoff, $dryRun, fn (string $file) => isset($known[$file]));

        $verb = $dryRun ? 'Будет удалено' : 'Удалено';
        $this->info("{$verb}: temp — {$temp}, media — {$media}");

        return self::SUCCESS;
    }

    /**
     * Temp paths named by jobs waiting in the database queue (a backlog older
     * than the age window). Other queue drivers rely on the window alone.
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
