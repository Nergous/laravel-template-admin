<?php

namespace App\Services\System\Backup;

use App\Enums\BackupRequestStatus;

/** Result of BackupManager::requestManual(); message is set for Failed. */
final class ManualBackupResult
{
    public function __construct(
        public readonly BackupRequestStatus $status,
        public readonly ?string $message = null,
    ) {}
}
