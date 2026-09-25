<?php

namespace App\Services;

use Illuminate\Http\File as HttpFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Database dumps: creation (app:db-backup and the "Create backup" button),
 * integrity check, optional encryption and offsite copy, per-kind rotation,
 * listing, deletion and restore (app:db-restore).
 *
 * File names are db-YYYYmmdd-HHiiss[-tag][-N].<ext>[.enc] with a UTC timestamp;
 * -N resolves collisions within one second. The tag is the dump kind: no tag
 * means a scheduled dump, "manual" one made from the admin panel. Each kind is
 * rotated separately (config('backup.keep')), so manual clicks never push the
 * scheduled history out.
 *
 * Supported drivers (the default connection):
 *   - sqlite        — VACUUM INTO: a consistent copy even while the app writes;
 *   - mysql/mariadb — mariadb-dump, or mysqldump when it is the only one installed
 *                     (password via MYSQL_PWD, not exposed in argv);
 *   - pgsql         — pg_dump (password via PGPASSWORD).
 *
 * Every dump is checked before it is kept: SQLite copies pass PRAGMA
 * integrity_check, SQL dumps must end with the tool's completion marker, and an
 * encrypted file must decrypt with the configured key.
 */
class BackupService
{
    public const KIND_SCHEDULED = 'scheduled';

    public const KIND_MANUAL = 'manual';

    /** Timestamp, optional tag, optional collision counter, extension, optional .enc. */
    private const NAME_PATTERN = '/^db-(\d{8}-\d{6})(?:-([a-z][a-z0-9]*))?(?:-\d+)?\.[a-z0-9]+(\.enc)?$/';

    public function __construct(private readonly BackupCipher $cipher) {}

    /**
     * Creates a dump, rotates old ones of the same kind and copies it offsite.
     *
     * A failed offsite copy does not undo the local dump: it is reported in
     * offsite_error so the caller can surface it.
     *
     * @param  string|null  $tag  Kind label (null — scheduled, "manual" — from the admin panel)
     * @param  int|null  $keep  Rotation override for this kind; null — from config
     * @return array{name: string, size: int, created_at: string, kind: string, encrypted: bool, rotated: int, offsite_error: string|null}
     */
    public function create(?string $tag = null, ?int $keep = null): array
    {
        if ($tag !== null && ! preg_match('/^[a-z][a-z0-9]{0,19}$/', $tag)) {
            throw new InvalidArgumentException('Метка должна состоять из латинских строчных букв и цифр и начинаться с буквы.');
        }

        $connection = (string) config('database.default');
        $config = config("database.connections.{$connection}");
        if (! is_array($config)) {
            throw new RuntimeException("Соединение «{$connection}» не настроено.");
        }

        $driver = $config['driver'] ?? null;
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb', 'pgsql'], true)) {
            throw new RuntimeException("Драйвер «{$driver}» не поддерживается (нужен sqlite/mysql/mariadb/pgsql).");
        }

        // Validate the key before spending time on the dump.
        $key = $this->encryptionKey();

        $dir = $this->directory();
        File::ensureDirectoryExists($dir);

        $name = $this->uniqueName($dir, $tag, $driver === 'sqlite' ? 'sqlite' : 'sql', $key !== null);
        $target = $dir.DIRECTORY_SEPARATOR.$name;
        // The work file does not match db-*, so a half-written dump is never listed.
        $partial = $dir.DIRECTORY_SEPARATOR.'.partial-'.$name;

        try {
            match ($driver) {
                'sqlite' => DB::connection($connection)->statement('VACUUM INTO ?', [$partial]),
                'mysql', 'mariadb' => $this->dumpMysql($config, $partial),
                'pgsql' => $this->dumpPgsql($config, $partial),
            };

            $this->verify($partial, $driver);

            if ($key !== null) {
                $this->cipher->encrypt($partial, $target, $key);
                $this->verifyEncrypted($target, $dir);
            } elseif (! rename($partial, $target)) {
                throw new RuntimeException('Не удалось сохранить дамп: '.$target);
            }
        } catch (ProcessFailedException $e) {
            throw new RuntimeException(trim($e->getProcess()->getErrorOutput()) ?: $e->getMessage(), 0, $e);
        } finally {
            if (is_file($partial)) {
                @unlink($partial);
            }
        }

        $kind = $tag ?? self::KIND_SCHEDULED;
        $keep ??= $this->keepFor($kind);
        $rotated = $this->rotateLocal($kind, $keep);

        $offsiteError = null;
        if ($this->offsiteDisk() !== null) {
            try {
                $this->copyOffsite($target, $name);
                $this->rotateOffsite($kind, $keep);
            } catch (\Throwable $e) {
                report($e);
                $offsiteError = $e->getMessage();
            }
        }

        return $this->describe($target) + ['rotated' => $rotated, 'offsite_error' => $offsiteError];
    }

    /**
     * Replaces the current database with a local dump. A safety dump of the
     * current state (kind "prerestore") is taken first unless $safetyDump is false.
     *
     * @return string|null Name of the safety dump, if one was made
     */
    public function restore(string $file, bool $safetyDump = true): ?string
    {
        $path = $this->path($file);
        if ($path === null) {
            throw new RuntimeException("Резервная копия «{$file}» не найдена.");
        }

        $connection = (string) config('database.default');
        $config = (array) config("database.connections.{$connection}");
        $driver = $config['driver'] ?? null;

        $plainName = preg_replace('/\.enc$/', '', basename($path)) ?? '';
        $expected = $driver === 'sqlite' ? '.sqlite' : '.sql';
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb', 'pgsql'], true) || ! str_ends_with($plainName, $expected)) {
            throw new RuntimeException("Копия «{$file}» не подходит к текущему драйверу БД ({$driver}).");
        }

        if ($driver === 'sqlite' && ($config['database'] ?? ':memory:') === ':memory:') {
            throw new RuntimeException('Нельзя восстановить базу в памяти (:memory:).');
        }

        $safety = $safetyDump ? $this->create('prerestore')['name'] : null;

        $plain = $path;
        $temp = null;
        if (str_ends_with($path, '.enc')) {
            $temp = $this->directory().DIRECTORY_SEPARATOR.'.restore-'.$plainName;
            @unlink($temp);
            $this->decrypt($path, $temp);
            $plain = $temp;
        }

        try {
            $this->verify($plain, (string) $driver);

            match ($driver) {
                'sqlite' => $this->restoreSqlite($connection, (string) $config['database'], $plain),
                'mysql', 'mariadb' => $this->restoreMysql($config, $plain),
                'pgsql' => $this->restorePgsql($config, $plain),
            };
        } catch (ProcessFailedException $e) {
            throw new RuntimeException(trim($e->getProcess()->getErrorOutput()) ?: $e->getMessage(), 0, $e);
        } finally {
            if ($temp !== null && is_file($temp)) {
                @unlink($temp);
            }
        }

        return $safety;
    }

    /**
     * Local dumps, newest first. Has no side effects: a missing directory is an
     * empty list (the dashboard calls this on every render).
     *
     * @return list<array{name: string, size: int, created_at: string, kind: string, encrypted: bool}>
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

    /** @return array{name: string, size: int, created_at: string, kind: string, encrypted: bool}|null */
    public function latest(): ?array
    {
        return $this->list()[0] ?? null;
    }

    /** Absolute path of a local dump by its file name; null for a foreign or missing name. */
    public function path(string $file): ?string
    {
        if (! preg_match('/^db-[A-Za-z0-9_.-]+$/', $file) || str_contains($file, '..')) {
            return null;
        }

        $path = $this->directory().DIRECTORY_SEPARATOR.$file;

        return is_file($path) ? $path : null;
    }

    /**
     * Deletes a local dump. The offsite copy is kept on purpose: it is the
     * recovery path when the admin panel itself is compromised, and it has its
     * own rotation.
     *
     * @return bool false when there is no such dump
     */
    public function delete(string $file): bool
    {
        $path = $this->path($file);
        if ($path === null) {
            return false;
        }

        if (! @unlink($path)) {
            throw new RuntimeException('Не удалось удалить резервную копию.');
        }

        return true;
    }

    /** Decrypts an .enc dump with the configured key into $target (which must not exist). */
    public function decrypt(string $source, string $target): void
    {
        $key = $this->encryptionKey();
        if ($key === null) {
            throw new RuntimeException('BACKUP_ENCRYPTION_KEY не задан.');
        }

        $this->cipher->decrypt($source, $target, $key);
    }

    public function directory(): string
    {
        return rtrim((string) (config('backup.path') ?: storage_path('app/backups')), '\\/');
    }

    public function encryptionEnabled(): bool
    {
        return filled(config('backup.encryption_key'));
    }

    public function offsiteDisk(): ?string
    {
        $disk = config('backup.disk');

        return filled($disk) ? (string) $disk : null;
    }

    public function keepFor(string $kind): int
    {
        return (int) (config("backup.keep.{$kind}") ?? config('backup.keep.'.self::KIND_SCHEDULED, 7));
    }

    /** The dump kind by file name; names from older versions count as scheduled. */
    public static function kindOf(string $name): string
    {
        return preg_match(self::NAME_PATTERN, $name, $m) && ($m[2] ?? '') !== ''
            ? $m[2]
            : self::KIND_SCHEDULED;
    }

    /** @return array{name: string, size: int, created_at: string, kind: string, encrypted: bool} */
    private function describe(string $path): array
    {
        $name = basename($path);

        return [
            'name' => $name,
            'size' => (int) (filesize($path) ?: 0),
            'created_at' => $this->createdAt($name, fn (): int => (int) filemtime($path))->toIso8601String(),
            'kind' => self::kindOf($name),
            'encrypted' => str_ends_with($name, '.enc'),
        ];
    }

    /** The UTC time from the file name; $fallback (a timestamp) for names without one. */
    private function createdAt(string $name, callable $fallback): Carbon
    {
        if (preg_match(self::NAME_PATTERN, $name, $m)) {
            $stamp = Carbon::createFromFormat('Ymd-His', $m[1], 'UTC');
            if ($stamp !== false && $stamp !== null) {
                return $stamp;
            }
        }

        return Carbon::createFromTimestampUTC($fallback());
    }

    private function uniqueName(string $dir, ?string $tag, string $ext, bool $encrypted): string
    {
        $base = 'db-'.Carbon::now('UTC')->format('Ymd-His').($tag !== null ? "-{$tag}" : '');
        $suffix = ".{$ext}".($encrypted ? '.enc' : '');

        for ($n = 1; ; $n++) {
            $stem = $n === 1 ? $base : "{$base}-{$n}";
            $taken = file_exists("{$dir}/{$stem}.{$ext}") || file_exists("{$dir}/{$stem}.{$ext}.enc");

            if (! $taken) {
                return $stem.$suffix;
            }
        }
    }

    /** Deletes local dumps of $kind beyond the $keep newest; returns how many were deleted. */
    private function rotateLocal(string $kind, int $keep): int
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

    private function copyOffsite(string $path, string $name): void
    {
        $stored = Storage::disk($this->offsiteDisk())->putFileAs($this->offsitePath(), new HttpFile($path), $name);

        if ($stored === false) {
            throw new RuntimeException("Не удалось выгрузить копию на диск «{$this->offsiteDisk()}».");
        }
    }

    private function rotateOffsite(string $kind, int $keep): void
    {
        if ($keep <= 0) {
            return;
        }

        $disk = Storage::disk($this->offsiteDisk());

        $stale = collect($disk->files($this->offsitePath()))
            ->filter(fn (string $file): bool => str_starts_with(basename($file), 'db-') && self::kindOf(basename($file)) === $kind)
            ->sortByDesc(fn (string $file): string => $this->createdAt(basename($file), fn (): int => $disk->lastModified($file))->format('YmdHis').basename($file))
            ->slice($keep);

        if ($stale->isNotEmpty()) {
            $disk->delete($stale->values()->all());
        }
    }

    private function offsitePath(): string
    {
        return trim((string) config('backup.disk_path', 'backups'), '/');
    }

    /** The raw encryption key, or null when encryption is off. */
    private function encryptionKey(): ?string
    {
        return $this->encryptionEnabled()
            ? BackupCipher::decodeKey((string) config('backup.encryption_key'))
            : null;
    }

    /** @param  array<string, mixed>  $config */
    private function dumpMysql(array $config, string $target): void
    {
        $this->runToFile(new Process([
            $this->mysqlBinary('mariadb-dump', 'mysqldump'),
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 3306),
            '--user='.($config['username'] ?? ''),
            '--single-transaction',
            '--quick',
            '--no-tablespaces',
            (string) ($config['database'] ?? ''),
        ], env: ['MYSQL_PWD' => (string) ($config['password'] ?? '')], timeout: 1800), $target);
    }

    /** @param  array<string, mixed>  $config */
    private function dumpPgsql(array $config, string $target): void
    {
        $this->runToFile(new Process([
            'pg_dump',
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 5432),
            '--username='.($config['username'] ?? ''),
            '--no-owner',
            '--no-privileges',
            // DROP statements first, so app:db-restore can load the dump over a live schema.
            '--clean',
            '--if-exists',
            (string) ($config['database'] ?? ''),
        ], env: ['PGPASSWORD' => (string) ($config['password'] ?? '')], timeout: 1800), $target);
    }

    /**
     * The MariaDB client tools are named mariadb-*; the mysql* names are
     * deprecated aliases that recent packages no longer ship.
     */
    private function mysqlBinary(string $preferred, string $fallback): string
    {
        $finder = new ExecutableFinder;

        return $finder->find($preferred) ?? $finder->find($fallback) ?? $fallback;
    }

    /**
     * Rejects an empty or truncated dump before it replaces anything.
     *
     * @throws RuntimeException
     */
    private function verify(string $path, string $driver): void
    {
        clearstatcache(true, $path);
        $size = is_file($path) ? (int) filesize($path) : 0;
        if ($size === 0) {
            throw new RuntimeException('Проверка дампа не пройдена: файл пустой.');
        }

        if ($driver === 'sqlite') {
            $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $statement = $pdo->query('PRAGMA integrity_check');
            $result = $statement !== false ? $statement->fetchColumn() : false;
            // Release the handles before the file is renamed (Windows keeps it locked).
            $statement = null;
            $pdo = null;

            if ($result !== 'ok') {
                throw new RuntimeException('Проверка дампа не пройдена: PRAGMA integrity_check вернул ошибку.');
            }

            return;
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Не удалось прочитать дамп для проверки.');
        }
        fseek($handle, max(0, $size - 1024));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        $marker = $driver === 'pgsql' ? 'PostgreSQL database dump complete' : 'Dump completed';
        if (! str_contains($tail, $marker)) {
            throw new RuntimeException('Проверка дампа не пройдена: нет отметки о завершении, файл неполный.');
        }
    }

    /** Makes sure the freshly encrypted file decrypts with the configured key. */
    private function verifyEncrypted(string $path, string $dir): void
    {
        $check = $dir.DIRECTORY_SEPARATOR.'.verify-'.basename($path);
        @unlink($check);

        try {
            $this->decrypt($path, $check);
        } catch (\Throwable $e) {
            @unlink($path);

            throw new RuntimeException('Проверка зашифрованной копии не пройдена: '.$e->getMessage(), 0, $e);
        } finally {
            @unlink($check);
        }
    }

    private function restoreSqlite(string $connection, string $database, string $source): void
    {
        DB::disconnect($connection);

        $staging = $database.'.restoring';
        if (! copy($source, $staging)) {
            throw new RuntimeException('Не удалось скопировать дамп рядом с базой.');
        }

        // Stale WAL files would be replayed over the restored database.
        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            @unlink($database.$suffix);
        }

        if (! rename($staging, $database)) {
            @unlink($staging);

            throw new RuntimeException('Не удалось заменить файл базы данных.');
        }

        DB::purge($connection);
    }

    /** @param  array<string, mixed>  $config */
    private function restoreMysql(array $config, string $source): void
    {
        $this->runFromFile(new Process([
            $this->mysqlBinary('mariadb', 'mysql'),
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 3306),
            '--user='.($config['username'] ?? ''),
            (string) ($config['database'] ?? ''),
        ], env: ['MYSQL_PWD' => (string) ($config['password'] ?? '')], timeout: 3600), $source);
    }

    /** @param  array<string, mixed>  $config */
    private function restorePgsql(array $config, string $source): void
    {
        $this->runFromFile(new Process([
            'psql',
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 5432),
            '--username='.($config['username'] ?? ''),
            '--dbname='.($config['database'] ?? ''),
            '--single-transaction',
            '--set=ON_ERROR_STOP=1',
            '--quiet',
        ], env: ['PGPASSWORD' => (string) ($config['password'] ?? '')], timeout: 3600), $source);
    }

    /** Feeds $source to the process stdin; throws ProcessFailedException on a non-zero exit. */
    private function runFromFile(Process $process, string $source): void
    {
        $handle = fopen($source, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Не удалось открыть дамп: '.$source);
        }

        try {
            $process->setInput($handle);
            $process->mustRun();
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /** Streams the process stdout into $target; throws ProcessFailedException on a non-zero exit. */
    private function runToFile(Process $process, string $target): void
    {
        $handle = fopen($target, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Не удалось открыть файл для записи: '.$target);
        }

        try {
            $process->run(function (string $type, string $buffer) use ($handle): void {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);
                }
            });
        } finally {
            fclose($handle);
        }

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }
}
