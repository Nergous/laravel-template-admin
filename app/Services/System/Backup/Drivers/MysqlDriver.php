<?php

namespace App\Services\System\Backup\Drivers;

use Symfony\Component\Process\Process;

/**
 * MySQL and MariaDB: mariadb-dump, or mysqldump when only that is installed.
 * The password is passed via MYSQL_PWD, not in the arguments.
 */
class MysqlDriver extends ExternalToolDriver
{
    public function dump(string $connection, array $config, string $target): void
    {
        $this->runToFile(new Process([
            $this->binary('mariadb-dump', 'mysqldump'),
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 3306),
            '--user='.($config['username'] ?? ''),
            '--single-transaction',
            '--quick',
            '--no-tablespaces',
            (string) ($config['database'] ?? ''),
        ], env: $this->env($config), timeout: $this->dumpTimeout()), $target);
    }

    protected function completionMarker(): string
    {
        return 'Dump completed';
    }

    protected function loader(array $config): Process
    {
        return new Process([
            $this->binary('mariadb', 'mysql'),
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 3306),
            '--user='.($config['username'] ?? ''),
            (string) ($config['database'] ?? ''),
        ], env: $this->env($config), timeout: self::RESTORE_TIMEOUT);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private function env(array $config): array
    {
        return ['MYSQL_PWD' => (string) ($config['password'] ?? '')];
    }
}
