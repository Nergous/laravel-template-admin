<?php

namespace App\Services\System\Backup;

use Illuminate\Container\Attributes\Config;

/** Backup settings (config/backup.php) with type casting and defaults. */
class BackupConfig
{
    /**
     * @param  array<string, mixed>|null  $keep  How many copies of each kind to keep
     */
    public function __construct(
        #[Config('backup.path')] private readonly ?string $path,
        #[Config('backup.disk')] private readonly ?string $disk,
        #[Config('backup.disk_path', 'backups')] private readonly ?string $diskPath,
        #[Config('backup.keep')] private readonly ?array $keep,
        #[Config('backup.timeout', 600)] private readonly mixed $timeout,
        #[Config('backup.binary_path')] private readonly ?string $binaryPath,
    ) {}

    /** Local dump directory without a trailing separator. */
    public function directory(): string
    {
        return rtrim((string) ($this->path ?: storage_path('app/backups')), '\\/');
    }

    /** Offsite copy disk; null — offsite copy disabled. */
    public function offsiteDisk(): ?string
    {
        return filled($this->disk) ? (string) $this->disk : null;
    }

    /** Backup directory on the offsite disk. */
    public function offsitePath(): string
    {
        return trim((string) ($this->diskPath ?? 'backups'), '/');
    }

    /** How many copies of a kind to keep; kinds without their own number are kept like scheduled ones. <= 0 — never delete. */
    public function keepFor(string $kind): int
    {
        return (int) ($this->keep[$kind] ?? $this->keep['scheduled'] ?? 7);
    }

    /**
     * Seconds the dump tool may run. The manual dump job (App\Jobs\CreateBackup)
     * gets more (jobTimeout()), so this limit stops the tool while the job still
     * has time to clean up and report the failure.
     */
    public function dumpTimeout(): int
    {
        return max(60, (int) ($this->timeout ?? 600));
    }

    /** Manual dump job timeout: the tool limit plus a margin for verification and upload. */
    public function jobTimeout(): int
    {
        return $this->dumpTimeout() + 60;
    }

    /** Directory with the database client tools when they are not in PATH; empty — PATH only. */
    public function binaryPath(): string
    {
        return rtrim((string) $this->binaryPath, '\\/');
    }
}
