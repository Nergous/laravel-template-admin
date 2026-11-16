<?php

namespace App\Services\System\Backup;

use App\Enums\BackupKind;
use App\Exceptions\BackupFailedException;
use App\Services\System\Backup\Drivers\BackupDriver;
use Illuminate\Contracts\Config\Repository as Config;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Creating and restoring database dumps: the app:db-backup and
 * app:db-restore commands and the "Create backup" button. Every dump is verified
 * before it enters the list; then older copies of the same kind are rotated and,
 * when configured, an offsite copy is made.
 *
 * Files and names live in BackupFiles, the offsite copy in OffsiteBackups.
 * Creating, verifying and loading a dump depend on the database and live in
 * Drivers\* (BackupDrivers picks the default connection's driver): SQLite, MySQL/MariaDB, PostgreSQL.
 */
class BackupService
{
    /**
     * Cache key the scheduler sets when the nightly app:db-backup failed and
     * clears after a successful run (routes/console.php); the backups page warns
     * about the problem by it.
     */
    public const SCHEDULED_FAILURE_KEY = 'backups.scheduled_failure';

    public function __construct(
        private readonly BackupDrivers $drivers,
        private readonly BackupFiles $files,
        private readonly OffsiteBackups $offsite,
        private readonly BackupConfig $config,
        private readonly Config $appConfig,
    ) {}

    /**
     * Creates a dump, deletes older copies of the same kind and uploads the offsite copy.
     *
     * An offsite failure does not undo the local dump: it is returned in
     * offsite_error so the caller can report it.
     *
     * @param  string|null  $tag  Kind tag (null — scheduled, see BackupKind)
     * @param  int|null  $keep  How many copies of this kind to keep; null — from the settings
     * @return array{name: string, size: int, created_at: string, kind: string, rotated: int, offsite_error: string|null}
     *
     * @throws RuntimeException
     */
    public function create(?string $tag = null, ?int $keep = null): array
    {
        if ($tag !== null && ! preg_match('/^[a-z][a-z0-9]{0,19}$/', $tag)) {
            throw new BackupFailedException('Метка должна состоять из латинских строчных букв и цифр и начинаться с буквы.');
        }

        [$connection, $config] = $this->connection();
        if ($config === []) {
            throw new BackupFailedException("Соединение «{$connection}» не настроено.");
        }

        $driver = $this->drivers->for($config['driver'] ?? null);
        ['name' => $name, 'target' => $target, 'partial' => $partial] = $this->files->reserve($tag, $driver->extension());

        try {
            $driver->dump($connection, $config, $partial);
            $this->verify($driver, $partial);

            if (! rename($partial, $target)) {
                throw new RuntimeException('Не удалось сохранить дамп: '.$target);
            }
        } catch (ProcessFailedException $e) {
            throw $this->toolFailure($e);
        } finally {
            if (is_file($partial)) {
                @unlink($partial);
            }
        }

        $kind = $tag ?? BackupKind::Scheduled->value;
        $keep ??= $this->config->keepFor($kind);
        $rotated = $this->files->rotate($kind, $keep);

        $offsiteError = null;
        if ($this->offsite->disk() !== null) {
            try {
                $this->offsite->upload($target, $name, $kind, $keep);
            } catch (RuntimeException $e) {
                report($e);
                $offsiteError = $e->getMessage();
            }
        }

        return $this->files->describe($target) + ['rotated' => $rotated, 'offsite_error' => $offsiteError];
    }

    /**
     * A copy of the current state before a restore (the prerestore kind).
     *
     * @return string Copy name
     */
    public function safetyDump(): string
    {
        return $this->create(BackupKind::Prerestore->tag())['name'];
    }

    /**
     * Replaces the current database with a local dump. The caller makes the copy
     * of the current state beforehand (safetyDump()).
     *
     * Server databases are emptied before loading (see
     * Drivers\ExternalToolDriver::restore()). The dump is verified first, so a
     * truncated file never gets loaded; if loading still fails, the way back is
     * the copy of the current state.
     *
     * Stop a queue worker and the scheduler first (app:db-restore puts the
     * application into maintenance mode): another process writing during the
     * load or holding the SQLite file open would mix old and new data.
     *
     * @throws RuntimeException
     */
    public function restore(string $file): void
    {
        $path = $this->assertRestorable($file);

        [$connection, $config] = $this->connection();
        $driver = $this->drivers->for($config['driver'] ?? null);

        try {
            $this->verify($driver, $path);
            $driver->restore($connection, $config, $path);
        } catch (ProcessFailedException $e) {
            throw $this->toolFailure($e);
        }
    }

    /**
     * Checks that $file can replace the current database (it exists, matches the
     * driver, the database is not :memory:) without touching anything.
     *
     * @return string Absolute path of the dump
     *
     * @throws BackupFailedException
     */
    public function assertRestorable(string $file): string
    {
        $path = $this->files->path($file);
        if ($path === null) {
            throw new BackupFailedException("Резервная копия «{$file}» не найдена.");
        }

        [, $config] = $this->connection();
        $name = $config['driver'] ?? null;

        if (! $this->drivers->supports($name) || ! str_ends_with($path, '.'.$this->drivers->for($name)->extension())) {
            throw new BackupFailedException("Копия «{$file}» не подходит к текущему драйверу БД ({$name}).");
        }

        $this->drivers->for($name)->ensureRestorable($config);

        return $path;
    }

    /** Driver of the default connection (sqlite, mysql …). */
    public function driver(): ?string
    {
        return $this->connection()[1]['driver'] ?? null;
    }

    /**
     * Name of the default connection and its settings; empty settings when the
     * connection is not configured.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function connection(): array
    {
        $connection = (string) $this->appConfig->get('database.default');
        $config = $this->appConfig->get("database.connections.{$connection}");

        return [$connection, is_array($config) ? $config : []];
    }

    /**
     * Rejects an empty file, then lets the driver check the content.
     *
     * @throws RuntimeException
     */
    private function verify(BackupDriver $driver, string $path): void
    {
        clearstatcache(true, $path);
        if ((is_file($path) ? (int) filesize($path) : 0) === 0) {
            throw new BackupFailedException('Проверка дампа не пройдена: файл пустой.');
        }

        $driver->verify($path);
    }

    /**
     * The tool's own error output says more than the exit code. It may contain
     * hosts and paths, so this is not a BackupFailedException: the UI shows a
     * generic message.
     */
    private function toolFailure(ProcessFailedException $e): RuntimeException
    {
        return new RuntimeException(trim($e->getProcess()->getErrorOutput()) ?: $e->getMessage(), 0, $e);
    }
}
