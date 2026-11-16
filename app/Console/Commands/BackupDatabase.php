<?php

namespace App\Console\Commands;

use App\Services\System\Backup\BackupFiles;
use App\Services\System\Backup\BackupService;
use App\Services\System\Backup\OffsiteBackups;
use Exception;
use Illuminate\Console\Command;

/**
 * Dumps the database into config('backup.path'). File names, the offsite copy
 * and rotation per kind live in App\Services\System\Backup.
 *
 * The scheduler runs the command without a tag (routes/console.php); manually:
 *   php artisan app:db-backup [--tag=manual] [--keep=N]
 */
class BackupDatabase extends Command
{
    protected $signature = 'app:db-backup
        {--tag= : Вид копии (латиница и цифры, например manual); без метки — плановая}
        {--keep= : Сколько копий этого вида хранить (по умолчанию из config/backup.php; <=0 — не удалять)}';

    protected $description = 'Создаёт дамп БД (с внешней копией, если она настроена) и удаляет старые копии того же вида';

    public function handle(BackupService $backups, BackupFiles $files, OffsiteBackups $offsite): int
    {
        $tag = $this->option('tag');
        $keep = $this->option('keep');

        try {
            $result = $backups->create(
                filled($tag) ? (string) $tag : null,
                is_numeric($keep) ? (int) $keep : null,
            );
        } catch (Exception $e) {
            // The scheduler does not keep the console output: the reason stays in the log.
            report($e);
            $this->error('Дамп не создан: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Дамп создан: '.$files->directory().DIRECTORY_SEPARATOR.$result['name'].' ('.$this->humanSize($result['size']).')');

        if ($result['rotated'] > 0) {
            $this->line('Удалено старых дампов: '.$result['rotated']);
        }

        if ($result['offsite_error'] !== null) {
            // A non-zero exit code tells the scheduler and monitoring that there is no offsite copy.
            $this->error('Копия сохранена локально, но не выгружена на внешний диск: '.$result['offsite_error']);

            return self::FAILURE;
        }

        if ($offsite->disk() !== null) {
            $this->line('Копия выгружена на диск «'.$offsite->disk().'».');
        }

        return self::SUCCESS;
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }

        return round($n, 1).' '.$units[$i];
    }
}
