<?php

namespace App\Services\System\Backup;

use App\Enums\BackupKind;
use App\Exceptions\BackupFailedException;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;

/**
 * Local dumps in config('backup.path'): names, listing, lookup by name,
 * deletion, rotation and cleanup of work files.
 *
 * Names are db-YYYYMMDD-HHMMSS[-tag][-N].<extension> with the time in UTC; -N
 * separates copies made within one second. The tag is the copy kind (BackupKind):
 * no tag — scheduled.
 *
 * Work files (.partial-*) do not match db-*, so an unfinished dump never shows
 * up in the list. A process killed mid-dump cannot delete its work file, so
 * before every new dump work files older than STALE_WORK_FILE_SECONDS are
 * removed.
 */
class BackupFiles
{
    /**
     * Work files older than this are leftovers of a killed process. Longer than any
     * dump run, so a running dump keeps its file.
     */
    public const STALE_WORK_FILE_SECONDS = 7200;

    /** Work file prefix; it does not match the db-* listing pattern. */
    private const WORK_FILE_PREFIX = '.partial-';

    /** Time, optional tag, optional collision counter, extension. */
    private const NAME_PATTERN = '/^db-(\d{8}-\d{6})(?:-([a-z][a-z0-9]*))?(?:-\d+)?\.[a-z0-9]+$/';

    public function __construct(
        private readonly BackupConfig $config,
        private readonly Filesystem $files,
    ) {}

    public function directory(): string
    {
        return $this->config->directory();
    }

    /**
     * Local dumps, newest first. A missing directory gives an empty list and is
     * not created.
     *
     * @return list<array{name: string, size: int, created_at: string, kind: string}>
     */
    public function list(): array
    {
        $directory = $this->directory();
        if (! is_dir($directory)) {
            return [];
        }

        return collect(glob($directory.DIRECTORY_SEPARATOR.'db-*') ?: [])
            ->filter(fn (string $path): bool => is_file($path))
            ->map(fn (string $path): array => $this->describe($path))
            ->sort(fn (array $a, array $b): int => [$b['created_at'], $b['name']] <=> [$a['created_at'], $a['name']])
            ->values()
            ->all();
    }

    /** @return array{name: string, size: int, created_at: string, kind: string}|null */
    public function latest(): ?array
    {
        return $this->list()[0] ?? null;
    }

    /** Absolute path of a local dump by file name; null for a foreign or missing name. */
    public function path(string $file): ?string
    {
        if (! preg_match('/^db-[A-Za-z0-9_.-]+$/', $file) || str_contains($file, '..')) {
            return null;
        }

        $path = $this->directory().DIRECTORY_SEPARATOR.$file;

        return is_file($path) ? $path : null;
    }

    /**
     * Deletes a local dump. The offsite copy stays on purpose: it is the way back
     * if the admin panel itself is compromised, and it has its own rotation.
     *
     * @return bool false — no such dump
     */
    public function delete(string $file): bool
    {
        $path = $this->path($file);
        if ($path === null) {
            return false;
        }

        if (! @unlink($path)) {
            throw new BackupFailedException('Не удалось удалить резервную копию.');
        }

        return true;
    }

    /**
     * Prepares room for a new dump: creates the directory, removes old work files
     * and picks a free name.
     *
     * @return array{name: string, target: string, partial: string} Name, final and work path
     */
    public function reserve(?string $tag, string $extension): array
    {
        $directory = $this->directory();
        $this->files->ensureDirectoryExists($directory);
        $this->deleteStaleWorkFiles();

        $name = $this->uniqueName($directory, $tag, $extension);

        return [
            'name' => $name,
            'target' => $directory.DIRECTORY_SEPARATOR.$name,
            'partial' => $directory.DIRECTORY_SEPARATOR.self::WORK_FILE_PREFIX.$name,
        ];
    }

    /**
     * Deletes work files (.partial-*) older than STALE_WORK_FILE_SECONDS: leftovers
     * of a process killed by a timeout or a restart.
     *
     * @return int How many files were deleted
     */
    public function deleteStaleWorkFiles(): int
    {
        $directory = $this->directory();
        if (! is_dir($directory)) {
            return 0;
        }

        $threshold = now()->getTimestamp() - self::STALE_WORK_FILE_SECONDS;
        $deleted = 0;

        foreach (glob($directory.DIRECTORY_SEPARATOR.self::WORK_FILE_PREFIX.'*') ?: [] as $path) {
            clearstatcache(true, $path);
            if (is_file($path) && (int) filemtime($path) < $threshold && @unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /** Deletes local dumps of kind $kind beyond the $keep newest; returns how many were deleted. */
    public function rotate(string $kind, int $keep): int
    {
        if ($keep <= 0) {
            return 0;
        }

        $stale = collect($this->list())
            ->filter(fn (array $backup): bool => $backup['kind'] === $kind)
            ->slice($keep);

        foreach ($stale as $backup) {
            @unlink($this->directory().DIRECTORY_SEPARATOR.$backup['name']);
        }

        return $stale->count();
    }

    /** @return array{name: string, size: int, created_at: string, kind: string} */
    public function describe(string $path): array
    {
        $name = basename($path);

        return [
            'name' => $name,
            'size' => (int) (filesize($path) ?: 0),
            'created_at' => (self::timeOf($name) ?? Carbon::createFromTimestampUTC((int) filemtime($path)))->toIso8601String(),
            'kind' => self::kindOf($name),
        ];
    }

    /** Dump kind by file name; names of older versions count as scheduled. */
    public static function kindOf(string $name): string
    {
        return preg_match(self::NAME_PATTERN, $name, $m) && ($m[2] ?? '') !== ''
            ? $m[2]
            : BackupKind::Scheduled->value;
    }

    /** Time from the file name (UTC); null for a name without a time. */
    public static function timeOf(string $name): ?Carbon
    {
        if (! preg_match(self::NAME_PATTERN, $name, $m)) {
            return null;
        }

        $stamp = Carbon::createFromFormat('Ymd-His', $m[1], 'UTC');

        return $stamp instanceof Carbon ? $stamp : null;
    }

    private function uniqueName(string $directory, ?string $tag, string $extension): string
    {
        $base = 'db-'.Carbon::now('UTC')->format('Ymd-His').($tag !== null ? "-{$tag}" : '');

        for ($n = 1; ; $n++) {
            $name = ($n === 1 ? $base : "{$base}-{$n}").".{$extension}";

            if (! file_exists($directory.DIRECTORY_SEPARATOR.$name)) {
                return $name;
            }
        }
    }
}
