<?php

namespace App\Services\System\Backup\Drivers;

use App\Exceptions\BackupFailedException;
use App\Services\System\Backup\BackupConfig;
use Illuminate\Database\DatabaseManager;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * A server database dumped and loaded with its client tools (mariadb-dump,
 * pg_dump, psql …). A complete dump ends with the tool's completion marker;
 * before loading, every table and view of the schema is dropped.
 */
abstract class ExternalToolDriver implements BackupDriver
{
    /** Seconds a restore may run. */
    protected const RESTORE_TIMEOUT = 3600;

    public function __construct(
        protected readonly BackupConfig $config,
        protected readonly DatabaseManager $db,
    ) {}

    /** Text the dump tool writes at the very end of a complete dump. */
    abstract protected function completionMarker(): string;

    /**
     * The client process that loads the dump from stdin.
     *
     * @param  array<string, mixed>  $config
     */
    abstract protected function loader(array $config): Process;

    public function extension(): string
    {
        return 'sql';
    }

    public function ensureRestorable(array $config): void {}

    public function verify(string $path): void
    {
        $size = (int) filesize($path);
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new BackupFailedException('Не удалось прочитать дамп для проверки.');
        }
        fseek($handle, max(0, $size - 1024));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        if (! str_contains($tail, $this->completionMarker())) {
            throw new BackupFailedException('Проверка дампа не пройдена: нет отметки о завершении, файл неполный.');
        }
    }

    /**
     * First drops every table and view of the schema: a dump made before a new
     * migration does not know its tables, and loading over them would leave a
     * schema the migrations table no longer describes. The client is looked up
     * before dropping, so without the tool the database stays untouched.
     */
    public function restore(string $connection, array $config, string $source): void
    {
        $loader = $this->loader($config);

        $schema = $this->db->connection($connection)->getSchemaBuilder();
        $schema->dropAllViews();
        $schema->dropAllTables();
        $this->db->purge($connection);

        $this->runFromFile($loader, $source);
    }

    /**
     * Full path of the first client tool found: first in backup.binary_path,
     * then in PATH. MariaDB tools are named mariadb-*; the mysql* names are
     * deprecated aliases missing from newer packages.
     *
     * @throws BackupFailedException when none of the names is installed
     */
    protected function binary(string ...$names): string
    {
        $dir = $this->config->binaryPath();
        $finder = new ExecutableFinder;

        foreach ($names as $name) {
            foreach (DIRECTORY_SEPARATOR === '\\' ? ['.exe', ''] : [''] as $suffix) {
                $file = $dir.DIRECTORY_SEPARATOR.$name.$suffix;
                if ($dir !== '' && is_file($file) && (DIRECTORY_SEPARATOR === '\\' || is_executable($file))) {
                    return $file;
                }
            }
        }

        foreach ($names as $name) {
            if (($file = $finder->find($name)) !== null) {
                return $file;
            }
        }

        throw new BackupFailedException(sprintf(
            'Не найдена программа %s. Установите клиент базы данных на сервер приложения или укажите папку с программой в BACKUP_BINARY_PATH.',
            implode(' или ', $names),
        ));
    }

    protected function dumpTimeout(): int
    {
        return $this->config->dumpTimeout();
    }

    /** Streams the process stdout into $target; throws ProcessFailedException on a non-zero exit code. */
    protected function runToFile(Process $process, string $target): void
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

    /** Feeds $source into the process stdin; throws ProcessFailedException on a non-zero exit code. */
    protected function runFromFile(Process $process, string $source): void
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
}
