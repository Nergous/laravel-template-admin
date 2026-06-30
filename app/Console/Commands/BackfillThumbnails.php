<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\ImageOptimizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Generates thumbnail versions for already uploaded WebP images.
 *
 * Walks the specified folders on the public disk (media by default) and, for each
 * *.webp file that does not yet have a corresponding *.thumb.webp, creates a
 * resized copy.
 *
 * Usage:
 *   php artisan media:backfill-thumbs
 *   php artisan media:backfill-thumbs --dir=media --dir=avatars
 */
class BackfillThumbnails extends Command
{
    protected $signature = 'media:backfill-thumbs
                            {--dir=* : Ограничить список каталогов (по умолчанию: media)}';

    protected $description = 'Генерирует thumbnail для уже существующих WebP-изображений';

    /**
     * Walks the directories (--dir, media by default) and creates thumbnails for
     * *.webp files that do not yet have one. Re-running is safe.
     *
     * @return int self::SUCCESS if there were no errors; self::FAILURE when failed > 0.
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
                    $this->markHasThumb($file); // legacy rows without the flag
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
     * Marks media rows with the given file as having a thumbnail (has_thumb=true).
     *
     * Needed for legacy rows created before the has_thumb column was introduced:
     * after backfill the thumbnails exist on disk, but the flag is still false and
     * the listing does not return them. Files without a DB row (for example,
     * avatars) are simply not found — this is expected.
     *
     * @param  string  $file  Path to the WebP file relative to the public disk (the filename column value)
     */
    private function markHasThumb(string $file): void
    {
        Media::where('filename', $file)
            ->where('has_thumb', false)
            ->update(['has_thumb' => true]);
    }
}
