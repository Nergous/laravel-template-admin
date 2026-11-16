<?php

namespace App\Console\Commands;

use App\Events\ActivityOccurred;
use App\Services\System\Backup\BackupFiles;
use App\Services\System\Backup\BackupService;
use Exception;
use Illuminate\Console\Command;

/**
 * Restores the database from a local dump (see BackupService::restore()):
 *   php artisan app:db-restore db-20260924-030000.sqlite
 *   php artisan app:db-restore db-20260924-030000.sql --force
 *
 * First a copy of the current state is saved (the "prerestore" kind) unless
 * --no-safety-backup is given.
 *
 * During the restore the application is put into maintenance mode and brought
 * back afterwards, also on failure. If maintenance mode was on before the run, it
 * stays on. A queue worker and the scheduler are separate processes: stop them
 * before running the command, otherwise they keep writing to the database
 * while it is replaced, and with SQLite they keep the database file open. After
 * the restore the cache is cleared (cache:clear, permission:cache-reset): it holds
 * the settings and permissions of the replaced database.
 */
class RestoreBackup extends Command
{
    protected $signature = 'app:db-restore
        {file : Имя копии в каталоге резервных копий}
        {--force : Не спрашивать подтверждение}
        {--no-safety-backup : Не делать копию текущего состояния перед восстановлением}';

    protected $description = 'Восстанавливает БД из резервной копии (текущие данные будут заменены)';

    public function handle(BackupService $backups, BackupFiles $files): int
    {
        $file = (string) $this->argument('file');

        if ($files->path($file) === null) {
            $this->error("Резервная копия «{$file}» не найдена в {$files->directory()}.");

            return self::FAILURE;
        }

        try {
            $backups->assertRestorable($file);
        } catch (Exception $e) {
            $this->error('Восстановление не выполнено: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->warn('Перед восстановлением остановите планировщик (в Docker: docker compose stop scheduler) и обработчик очереди, если он запущен,'
            .' иначе они продолжат писать в базу во время замены.');

        if (! $this->option('force')
            && ! $this->confirm('Текущая база «'.config('database.default')."» будет заменена данными из {$file}. Планировщик и обработчик очереди остановлены?")) {
            $this->line('Отменено.');

            return self::FAILURE;
        }

        $maintenance = $this->laravel->maintenanceMode();
        $wasDown = $maintenance->active();
        // Saved to restore it later: with the cache maintenance driver cache:clear resets the flag.
        $downPayload = $wasDown ? $maintenance->data() : null;
        if (! $wasDown) {
            $this->callSilently('down', ['--retry' => 60]);
        }

        try {
            $safety = $this->option('no-safety-backup') ? null : $backups->safetyDump();
            $backups->restore($file);
        } catch (Exception $e) {
            $this->error('Восстановление не выполнено: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if (! $wasDown) {
                $this->runQuietly('up');
            }

            // Settings, permissions and other cached data belong to the replaced
            // database. Cleared on failure too: the database may be half-loaded. The media
            // usage index also lives in the default cache store, so cache:clear resets it
            // as well.
            $this->runQuietly('cache:clear');
            $this->runQuietly('permission:cache-reset');

            if ($wasDown && ! $maintenance->active()) {
                $maintenance->activate($downPayload ?? []);
            }
        }

        if ($safety !== null) {
            $this->line("Копия состояния до восстановления: {$safety}");
        }

        // Written into the restored database so the log shows where it came from.
        ActivityOccurred::by(null, null, 'backup_restored', null, $file);
        $this->info("База восстановлена из {$file}. Кэш очищен. Запустите планировщик (и обработчик очереди, если он используется) снова.");

        return self::SUCCESS;
    }

    /** Runs an extra command; its error is printed but does not hide the restore result. */
    private function runQuietly(string $command): void
    {
        try {
            if ($this->callSilently($command) !== self::SUCCESS) {
                $this->warn("Команда {$command} завершилась с ошибкой — выполните её вручную.");
            }
        } catch (Exception $e) {
            report($e);
            $this->warn("Команда {$command} не выполнена ({$e->getMessage()}) — выполните её вручную.");
        }
    }
}
