<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * Restores the database from a local dump (see BackupService::restore()):
 *   php artisan app:db-restore db-20260924-030000.sqlite
 *   php artisan app:db-restore db-20260924-030000.sql.enc --force
 *
 * A safety dump of the current state (kind "prerestore") is made first unless
 * --no-safety-backup is given. Stop the queue worker and the scheduler while
 * restoring, so nothing writes into the database mid-way.
 */
class RestoreBackup extends Command
{
    protected $signature = 'app:db-restore
        {file : Имя копии в каталоге резервных копий}
        {--force : Не спрашивать подтверждение}
        {--no-safety-backup : Не делать копию текущего состояния перед восстановлением}';

    protected $description = 'Восстанавливает БД из резервной копии (текущие данные будут заменены)';

    public function handle(BackupService $backups): int
    {
        $file = (string) $this->argument('file');

        if ($backups->path($file) === null) {
            $this->error("Резервная копия «{$file}» не найдена в {$backups->directory()}.");

            return self::FAILURE;
        }

        if (! $this->option('force')
            && ! $this->confirm('Текущая база «'.config('database.default')."» будет заменена данными из {$file}. Продолжить?")) {
            $this->line('Отменено.');

            return self::FAILURE;
        }

        try {
            $safety = $backups->restore($file, ! $this->option('no-safety-backup'));
        } catch (\Throwable $e) {
            $this->error('Восстановление не выполнено: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($safety !== null) {
            $this->line("Копия состояния до восстановления: {$safety}");
        }

        // Written into the restored database, so the history shows where it came from.
        ActivityLog::record(null, 'backup_restored', null, $file);
        $this->info("База восстановлена из {$file}.");

        return self::SUCCESS;
    }
}
