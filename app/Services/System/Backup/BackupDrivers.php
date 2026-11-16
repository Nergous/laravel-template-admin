<?php

namespace App\Services\System\Backup;

use App\Exceptions\BackupFailedException;
use App\Services\System\Backup\Drivers\BackupDriver;
use App\Services\System\Backup\Drivers\MysqlDriver;
use App\Services\System\Backup\Drivers\PgsqlDriver;
use App\Services\System\Backup\Drivers\SqliteDriver;
use Illuminate\Contracts\Container\Container;

/**
 * Picks the backup driver by the database driver name. A new database is
 * supported by adding a BackupDriver and a line in DRIVERS.
 */
class BackupDrivers
{
    /** @var array<string, class-string<BackupDriver>> */
    private const DRIVERS = [
        'sqlite' => SqliteDriver::class,
        'mysql' => MysqlDriver::class,
        'mariadb' => MysqlDriver::class,
        'pgsql' => PgsqlDriver::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function supports(?string $driver): bool
    {
        return isset(self::DRIVERS[$driver ?? '']);
    }

    /** @throws BackupFailedException for a database without a backup driver */
    public function for(?string $driver): BackupDriver
    {
        if (! $this->supports($driver)) {
            throw new BackupFailedException(sprintf(
                'Драйвер «%s» не поддерживается (нужен %s).',
                $driver,
                implode('/', array_keys(self::DRIVERS)),
            ));
        }

        return $this->container->make(self::DRIVERS[$driver]);
    }
}
