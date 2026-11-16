<?php

namespace App\Services\System\Backup\Drivers;

use RuntimeException;

/**
 * How one database driver creates, verifies and loads a dump. File names,
 * rotation and the offsite copy are the same for every driver and live outside it
 * (BackupService, BackupFiles, OffsiteBackups).
 */
interface BackupDriver
{
    /** Dump file extension without the dot ("sqlite", "sql"). */
    public function extension(): string;

    /**
     * Writes a dump of the connection to $target.
     *
     * @param  array<string, mixed>  $config  config("database.connections.<name>")
     */
    public function dump(string $connection, array $config, string $target): void;

    /**
     * Rejects a truncated or corrupted dump (an empty file is rejected earlier).
     *
     * @throws RuntimeException
     */
    public function verify(string $path): void;

    /**
     * Rejects a connection a dump cannot be loaded into, without touching anything.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws RuntimeException
     */
    public function ensureRestorable(array $config): void;

    /**
     * Replaces the connection's database with the dump content.
     *
     * @param  array<string, mixed>  $config
     */
    public function restore(string $connection, array $config, string $source): void;
}
