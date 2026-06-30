<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\ImageOptimizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Генерирует thumbnail-версии для уже загруженных WebP-изображений.
 *
 * Проходит по указанным папкам на диске public (по умолчанию — media) и для каждого
 * файла вида *.webp, у которого ещё нет соответствующего *.thumb.webp,
 * создаёт уменьшенную копию.
 *
 * Использование:
 *   php artisan media:backfill-thumbs
 *   php artisan media:backfill-thumbs --dir=media --dir=avatars
 */
class BackfillThumbnails extends Command
{
    protected $signature = 'media:backfill-thumbs
                            {--dir=* : Ограничить список каталогов (по умолчанию: media)}';

    protected $description = 'Генерирует thumbnail для уже существующих WebP-изображений';

    /**
     * Обходит каталоги (--dir, по умолчанию media) и создаёт миниатюры для *.webp,
     * у которых их ещё нет. Повторный запуск безопасен.
     *
     * @return int self::SUCCESS, если ошибок не было; self::FAILURE при failed > 0.
     */
    public function handle(): int
    {
        $dirs = $this->option('dir') ?: ['media'];
        $disk = Storage::disk('public');

        $created = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($dirs as $dir) {
            $files = $disk->allFiles($dir);

            foreach ($files as $file) {
                if (! str_ends_with($file, '.webp')) {
                    continue;
                }

                if (str_ends_with($file, '.thumb.webp')) {
                    continue;
                }

                $thumb = ImageOptimizer::thumbPath($file);

                if ($disk->exists($thumb)) {
                    $this->markHasThumb($file); // легаси-строки без флага
                    $skipped++;

                    continue;
                }

                if (! ImageOptimizer::generateThumbFor($file)) {
                    $failed++;
                    $this->warn("  ! не удалось обработать: {$file}");

                    continue;
                }

                if ($disk->exists($thumb)) {
                    $this->markHasThumb($file);
                    $created++;
                    $this->line("  + {$thumb}");
                } else {
                    $skipped++;
                }
            }
        }

        $this->info("Готово. Создано: {$created}, пропущено: {$skipped}, ошибок: {$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Помечает строки media с данным файлом как имеющие превью (has_thumb=true).
     *
     * Нужно для легаси-строк, созданных до введения колонки has_thumb: после
     * бэкофилла превью существуют на диске, но флаг ещё false, и список их не отдаёт.
     * Файлы без записи в БД (например, аватары) просто не находятся — это ожидаемо.
     *
     * @param  string  $file  Путь к WebP-файлу относительно диска public (значение колонки filename)
     */
    private function markHasThumb(string $file): void
    {
        Media::where('filename', $file)
            ->where('has_thumb', false)
            ->update(['has_thumb' => true]);
    }
}
