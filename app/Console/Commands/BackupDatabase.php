<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * Dumps the database into config('backup.path') (see App\Services\BackupService:
 * naming, encryption, offsite copy, per-kind rotation).
 *
 * Run by the scheduler without a tag (routes/console.php) and manually:
 *   php artisan app:db-backup [--tag=manual] [--keep=N]
 */
class BackupDatabase extends Command
{
    protected $signature = 'app:db-backup
        {--tag= : Вид копии (латиница и цифры, например manual); без метки — плановая}
        {--keep= : Сколько копий этого вида хранить (по умолчанию из config/backup.php; <=0 — не удалять)}';

    protected $description = 'Создаёт дамп БД (с шифрованием и внешней копией, если настроены) и удаляет старые копии того же вида';

    public function handle(BackupService $backups): int
    {
        $tag = $this->option('tag');
        $keep = $this->option('keep');

        try {
            $result = $backups->create(
                filled($tag) ? (string) $tag : null,
                is_numeric($keep) ? (int) $keep : null,
            );
        } catch (\Throwable $e) {
            $this->error('Дамп не создан: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Дамп создан: '.$backups->directory().DIRECTORY_SEPARATOR.$result['name'].' ('.$this->humanSize($result['size']).')');

        if ($result['rotated'] > 0) {
            $this->line('Удалено старых дампов: '.$result['rotated']);
        }

        if ($result['offsite_error'] !== null) {
            // A non-zero exit makes the scheduler/monitoring notice the missing offsite copy.
            $this->error('Копия сохранена локально, но не выгружена на внешний диск: '.$result['offsite_error']);

            return self::FAILURE;
        }

        if ($backups->offsiteDisk() !== null) {
            $this->line('Копия выгружена на диск «'.$backups->offsiteDisk().'».');
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
