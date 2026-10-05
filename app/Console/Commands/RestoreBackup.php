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
 * --no-safety-backup is given.
 *
 * While restoring, the application is in maintenance mode (unless it already
 * was, it is brought back up afterwards, also on failure). The queue worker and
 * the scheduler are separate processes: stop them before running this command,
 * otherwise they keep writing into the database mid-way — and with SQLite they
 * hold the database file open while it is replaced. Afterwards the cache is
 * cleared (cache:clear, permission:cache-reset): it still holds settings and
 * permissions of the replaced database.
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

        try {
            $backups->assertRestorable($file);
        } catch (\Throwable $e) {
            $this->error('Восстановление не выполнено: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->warn('Перед восстановлением остановите обработчик очереди и планировщик (в Docker: docker compose stop queue scheduler),'
            .' иначе они продолжат писать в базу во время замены.');

        if (! $this->option('force')
            && ! $this->confirm('Текущая база «'.config('database.default')."» будет заменена данными из {$file}. Обработчик очереди и планировщик остановлены?")) {
            $this->line('Отменено.');

            return self::FAILURE;
        }

        $maintenance = $this->laravel->maintenanceMode();
        $wasDown = $maintenance->active();
        // Kept to put it back: with the cache maintenance driver cache:clear drops the flag.
        $downPayload = $wasDown ? $maintenance->data() : null;
        if (! $wasDown) {
            $this->callSilently('down', ['--retry' => 60]);
        }

        try {
            $safety = $backups->restore($file, ! $this->option('no-safety-backup'));
        } catch (\Throwable $e) {
            $this->error('Восстановление не выполнено: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if (! $wasDown) {
                $this->runQuietly('up');
            }

            // Settings, permissions and other cached rows belong to the replaced
            // database; clear them on failure too, the database may be half-loaded.
            // The media usage index and the manual redirect map live in the default
            // cache store as well, so cache:clear drops them too.
            $this->runQuietly('cache:clear');
            $this->runQuietly('permission:cache-reset');

            if ($wasDown && ! $maintenance->active()) {
                $maintenance->activate($downPayload ?? []);
            }
        }

        if ($safety !== null) {
            $this->line("Копия состояния до восстановления: {$safety}");
        }

        // Written into the restored database, so the history shows where it came from.
        ActivityLog::record(null, 'backup_restored', null, $file);
        $this->info("База восстановлена из {$file}. Кэш очищен. Запустите обработчик очереди и планировщик снова.");

        return self::SUCCESS;
    }

    /** Runs a follow-up command; its failure is reported without hiding the restore result. */
    private function runQuietly(string $command): void
    {
        try {
            if ($this->callSilently($command) !== self::SUCCESS) {
                $this->warn("Команда {$command} завершилась с ошибкой — выполните её вручную.");
            }
        } catch (\Throwable $e) {
            report($e);
            $this->warn("Команда {$command} не выполнена ({$e->getMessage()}) — выполните её вручную.");
        }
    }
}
