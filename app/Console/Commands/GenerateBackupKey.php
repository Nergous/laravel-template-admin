<?php

namespace App\Console\Commands;

use App\Services\BackupCipher;
use Illuminate\Console\Command;

/**
 * Prints a new backup encryption key. It is not written anywhere: put it into
 * BACKUP_ENCRYPTION_KEY and keep a copy outside the server, otherwise the
 * encrypted dumps cannot be restored.
 */
class GenerateBackupKey extends Command
{
    protected $signature = 'app:backup-key';

    protected $description = 'Генерирует ключ шифрования резервных копий (BACKUP_ENCRYPTION_KEY)';

    public function handle(): int
    {
        try {
            $key = BackupCipher::generateKey();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line($key);
        $this->comment('Укажите ключ в BACKUP_ENCRYPTION_KEY и сохраните его копию вне сервера: без ключа зашифрованные копии не восстановить.');

        return self::SUCCESS;
    }
}
