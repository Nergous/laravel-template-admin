<?php

namespace App\Services\System\Backup\Drivers;

use App\Exceptions\BackupFailedException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use PDO;
use RuntimeException;

/**
 * SQLite: VACUUM INTO makes a consistent copy even while the application
 * writes; the copy must pass PRAGMA integrity_check.
 */
class SqliteDriver implements BackupDriver
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function extension(): string
    {
        return 'sqlite';
    }

    public function dump(string $connection, array $config, string $target): void
    {
        $this->db->connection($connection)->statement('VACUUM INTO ?', [$target]);
    }

    public function verify(string $path): void
    {
        $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $statement = $pdo->query('PRAGMA integrity_check');
        $result = $statement !== false ? $statement->fetchColumn() : false;
        // Release the handles before renaming the file (Windows keeps it locked).
        $statement = null;
        $pdo = null;

        if ($result !== 'ok') {
            throw new BackupFailedException('Проверка дампа не пройдена: PRAGMA integrity_check вернул ошибку.');
        }
    }

    public function ensureRestorable(array $config): void
    {
        if (($config['database'] ?? ':memory:') === ':memory:') {
            throw new BackupFailedException('Нельзя восстановить базу в памяти (:memory:).');
        }
    }

    public function restore(string $connection, array $config, string $source): void
    {
        $database = (string) $config['database'];

        // Merge the WAL into the main file first, so the -wal removed below is empty.
        // Other processes must not keep the database open (see BackupService::restore()).
        try {
            $this->db->connection($connection)->statement('PRAGMA wal_checkpoint(TRUNCATE)');
        } catch (QueryException) {
            // The database is not in WAL mode or the file is unreadable: nothing to merge.
        }

        $this->db->disconnect($connection);

        $staging = $database.'.restoring';
        if (! copy($source, $staging)) {
            throw new RuntimeException('Не удалось скопировать дамп рядом с базой.');
        }

        // Old WAL files would be replayed over the restored database.
        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            @unlink($database.$suffix);
        }

        if (! rename($staging, $database)) {
            @unlink($staging);

            throw new RuntimeException('Не удалось заменить файл базы данных.');
        }

        $this->db->purge($connection);
    }
}
