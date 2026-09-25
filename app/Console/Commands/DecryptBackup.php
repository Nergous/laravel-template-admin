<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * Decrypts a dump made with BACKUP_ENCRYPTION_KEY set:
 *   php artisan app:db-backup-decrypt db-20260924-030000.sqlite.enc --output=/tmp/restore.sqlite
 */
class DecryptBackup extends Command
{
    protected $signature = 'app:db-backup-decrypt
        {file : Путь к .enc-файлу или имя копии в каталоге резервных копий}
        {--output= : Куда записать расшифрованный дамп (по умолчанию — в текущий каталог без суффикса .enc)}';

    protected $description = 'Расшифровывает резервную копию БД ключом из BACKUP_ENCRYPTION_KEY';

    public function handle(BackupService $backups): int
    {
        $file = (string) $this->argument('file');
        $source = is_file($file) ? $file : $backups->path($file);

        if ($source === null) {
            $this->error("Файл «{$file}» не найден.");

            return self::FAILURE;
        }

        $output = (string) ($this->option('output')
            ?: getcwd().DIRECTORY_SEPARATOR.preg_replace('/\.enc$/', '', basename($source)));

        if (file_exists($output)) {
            $this->error("Файл «{$output}» уже существует — укажите другой --output.");

            return self::FAILURE;
        }

        try {
            $backups->decrypt($source, $output);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Расшифровано: '.$output);

        return self::SUCCESS;
    }
}
