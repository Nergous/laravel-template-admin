<?php

namespace App\Services\System\Backup\Drivers;

use Symfony\Component\Process\Process;

/**
 * PostgreSQL: pg_dump and psql. The password is passed via PGPASSWORD.
 */
class PgsqlDriver extends ExternalToolDriver
{
    public function dump(string $connection, array $config, string $target): void
    {
        $this->runToFile(new Process([
            $this->binary('pg_dump'),
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 5432),
            '--username='.($config['username'] ?? ''),
            '--no-owner',
            '--no-privileges',
            // DROP statements first, so app:db-restore can load the dump over a live schema.
            '--clean',
            '--if-exists',
            (string) ($config['database'] ?? ''),
        ], env: $this->env($config), timeout: $this->dumpTimeout()), $target);
    }

    protected function completionMarker(): string
    {
        return 'PostgreSQL database dump complete';
    }

    protected function loader(array $config): Process
    {
        return new Process([
            $this->binary('psql'),
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 5432),
            '--username='.($config['username'] ?? ''),
            '--dbname='.($config['database'] ?? ''),
            '--single-transaction',
            '--set=ON_ERROR_STOP=1',
            '--quiet',
        ], env: $this->env($config), timeout: self::RESTORE_TIMEOUT);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    private function env(array $config): array
    {
        return ['PGPASSWORD' => (string) ($config['password'] ?? '')];
    }
}
