<?php

/*
 * Database backups (app:db-backup, /admin/backups). See App\Services\System\Backup\BackupService.
 */
return [
    // Local directory for dumps.
    'path' => env('BACKUP_PATH') ?: storage_path('app/backups'),

    // How many recent dumps of each kind to keep; <= 0 disables rotation for that kind.
    // "scheduled" covers runs without --tag (the daily scheduler task), "manual" the
    // "Create backup" button. Other --tag values use the scheduled count.
    'keep' => [
        'scheduled' => (int) env('BACKUP_KEEP', 7),
        'manual' => (int) env('BACKUP_KEEP_MANUAL', 5),
    ],

    // Optional offsite copy: a disk name from config/filesystems.php (for example s3).
    // The same per-kind rotation is applied on that disk.
    'disk' => env('BACKUP_DISK'),
    'disk_path' => env('BACKUP_DISK_PATH', 'backups'),

    // Folder with the database client tools (mariadb-dump/mysqldump, mariadb/mysql,
    // pg_dump/psql) when they are not in PATH, for example when the database runs
    // in Docker and PHP runs on the host. It is checked before PATH.
    'binary_path' => env('BACKUP_BINARY_PATH'),

    // Seconds mariadb-dump/pg_dump may run (at least 60). The App\Jobs\CreateBackup
    // job gets 60 seconds more, so the tool limit fires first and the job reports
    // the error. The SQLite dump (VACUUM INTO) is not limited.
    'timeout' => (int) env('BACKUP_TIMEOUT', 600),
];
