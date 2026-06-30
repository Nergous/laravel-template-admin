<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Dumps the DB to storage/app/backups with rotation of old files.
 *
 * The driver is taken from the default connection (config('database.default')):
 *   - sqlite        — a copy of the DB file;
 *   - mysql/mariadb — mysqldump (password via the MYSQL_PWD env, not exposed in argv);
 *   - pgsql         — pg_dump (password via the PGPASSWORD env).
 *
 * For mysql/pgsql, mysqldump/pg_dump must be on the PATH (they are in the Docker image).
 * Run by the scheduler (routes/console.php, the scheduler service) and manually:
 *   php artisan app:db-backup [--keep=7]
 */
class BackupDatabase extends Command
{
    protected $signature = 'app:db-backup {--keep=7 : Сколько последних дампов хранить (ротация; <=0 — не удалять)}';

    protected $description = 'Создаёт дамп БД в storage/app/backups и удаляет старые сверх --keep';

    public function handle(): int
    {
        $connection = (string) config('database.default');
        $config = config("database.connections.{$connection}");

        if (! is_array($config)) {
            $this->error("Соединение «{$connection}» не настроено.");

            return self::FAILURE;
        }

        $dir = storage_path('app/backups');
        File::ensureDirectoryExists($dir);

        $driver = $config['driver'] ?? null;
        $stamp = now()->format('Y-m-d_His');

        try {
            $path = match ($driver) {
                'sqlite' => $this->backupSqlite($config, $dir, $stamp),
                'mysql', 'mariadb' => $this->backupMysql($config, $dir, $stamp),
                'pgsql' => $this->backupPgsql($config, $dir, $stamp),
                default => null,
            };
        } catch (ProcessFailedException $e) {
            $this->error('Дамп не создан: '.trim($e->getProcess()->getErrorOutput() ?: $e->getMessage()));

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('Дамп не создан: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($path === null) {
            $this->error("Драйвер «{$driver}» не поддерживается (нужен sqlite/mysql/mariadb/pgsql).");

            return self::FAILURE;
        }

        $this->info('Дамп создан: '.$path.' ('.$this->humanSize((int) (filesize($path) ?: 0)).')');
        $this->rotate($dir, (int) $this->option('keep'));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function backupSqlite(array $config, string $dir, string $stamp): string
    {
        $source = (string) ($config['database'] ?? '');
        if ($source === '' || $source === ':memory:' || ! is_file($source)) {
            throw new \RuntimeException('SQLite-файл не найден: '.($source !== '' ? $source : '(пусто)'));
        }

        $target = $this->targetPath($dir, pathinfo($source, PATHINFO_FILENAME), $stamp, 'sqlite');
        if (! copy($source, $target)) {
            throw new \RuntimeException('Не удалось скопировать SQLite-файл.');
        }

        return $target;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function backupMysql(array $config, string $dir, string $stamp): string
    {
        $target = $this->targetPath($dir, (string) ($config['database'] ?? 'database'), $stamp, 'sql');

        $process = new Process([
            'mysqldump',
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 3306),
            '--user='.($config['username'] ?? ''),
            '--single-transaction',
            '--quick',
            '--no-tablespaces',
            (string) ($config['database'] ?? ''),
        ], env: ['MYSQL_PWD' => (string) ($config['password'] ?? '')], timeout: 1800);

        $this->runToFile($process, $target);

        return $target;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function backupPgsql(array $config, string $dir, string $stamp): string
    {
        $target = $this->targetPath($dir, (string) ($config['database'] ?? 'database'), $stamp, 'sql');

        $process = new Process([
            'pg_dump',
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 5432),
            '--username='.($config['username'] ?? ''),
            '--no-owner',
            '--no-privileges',
            (string) ($config['database'] ?? ''),
        ], env: ['PGPASSWORD' => (string) ($config['password'] ?? '')], timeout: 1800);

        $this->runToFile($process, $target);

        return $target;
    }

    private function targetPath(string $dir, string $name, string $stamp, string $ext): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) ?: 'database';

        return $dir.DIRECTORY_SEPARATOR."db-{$safe}-{$stamp}.{$ext}";
    }

    /**
     * Streams the process stdout to a file; on a non-zero exit code it deletes the
     * partial file and throws ProcessFailedException (stderr ends up in the error message).
     */
    private function runToFile(Process $process, string $target): void
    {
        $handle = fopen($target, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Не удалось открыть файл для записи: '.$target);
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
            @unlink($target);
            throw new ProcessFailedException($process);
        }
    }

    /** Keeps the $keep most recent dumps and deletes the rest. */
    private function rotate(string $dir, int $keep): void
    {
        if ($keep <= 0) {
            return;
        }

        $stale = collect(glob($dir.DIRECTORY_SEPARATOR.'db-*') ?: [])
            ->filter(fn (string $f): bool => is_file($f))
            ->sortByDesc(fn (string $f): int => (int) filemtime($f))
            ->values()
            ->slice($keep);

        foreach ($stale as $file) {
            @unlink($file);
        }

        if ($stale->isNotEmpty()) {
            $this->line('Удалено старых дампов: '.$stale->count());
        }
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }

        return round($n, 1).' '.$units[$i];
    }
}
