<?php

namespace App\Services\System\Backup;

use App\Exceptions\BackupFailedException;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Http\File as HttpFile;
use Illuminate\Support\Carbon;

/**
 * Offsite copy of the dumps on the config('backup.disk') disk (e.g. S3) with the
 * same per-kind rotation as the local copies.
 */
class OffsiteBackups
{
    public function __construct(
        private readonly BackupConfig $config,
        private readonly FilesystemFactory $storage,
    ) {}

    /** Offsite disk name; null — offsite copy disabled. */
    public function disk(): ?string
    {
        return $this->config->offsiteDisk();
    }

    /**
     * Uploads a dump and deletes surplus copies of the same kind on the disk.
     *
     * @throws \RuntimeException when the upload or rotation fails
     */
    public function upload(string $path, string $name, string $kind, int $keep): void
    {
        $disk = $this->storage->disk($this->disk());

        if ($disk->putFileAs($this->config->offsitePath(), new HttpFile($path), $name) === false) {
            throw new BackupFailedException("Не удалось выгрузить копию на диск «{$this->disk()}».");
        }

        if ($keep <= 0) {
            return;
        }

        $stale = collect($disk->files($this->config->offsitePath()))
            ->filter(fn (string $file): bool => str_starts_with(basename($file), 'db-') && BackupFiles::kindOf(basename($file)) === $kind)
            ->sortByDesc(fn (string $file): string => $this->timeOf($file, $disk->lastModified(...))->format('YmdHis').basename($file))
            ->slice($keep);

        if ($stale->isNotEmpty()) {
            $disk->delete($stale->values()->all());
        }
    }

    /** Copy time from the file name; for names without a time, the modification time on the disk. */
    private function timeOf(string $file, callable $lastModified): Carbon
    {
        return BackupFiles::timeOf(basename($file)) ?? Carbon::createFromTimestampUTC((int) $lastModified($file));
    }
}
