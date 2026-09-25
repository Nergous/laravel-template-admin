<?php

/*
 * Database backups (app:db-backup, /admin/backups). See App\Services\BackupService.
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

    // Optional encryption key (32 bytes, base64; generate with php artisan app:backup-key).
    // When set, dumps are stored as *.enc (libsodium secretstream) and are restored
    // with php artisan app:db-backup-decrypt.
    'encryption_key' => env('BACKUP_ENCRYPTION_KEY'),

    // Optional offsite copy: a disk name from config/filesystems.php (for example s3).
    // The same per-kind rotation is applied on that disk.
    'disk' => env('BACKUP_DISK'),
    'disk_path' => env('BACKUP_DISK_PATH', 'backups'),
];
