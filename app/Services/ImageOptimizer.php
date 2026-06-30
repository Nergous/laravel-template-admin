<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Оптимизация изображений при загрузке:
 * - Конвертация в WebP (уменьшение размера на 30-50%)
 * - Ресайз по максимальной ширине
 * - Генерация thumbnail для карточек/лент
 * - Настраиваемое качество сжатия
 *
 * Если GD недоступен — сохраняет оригинал без обработки.
 */
class ImageOptimizer
{
    /** Ширина thumbnail (хватает для карточек до ~300px c 2x retina). */
    public const THUMB_MAX_WIDTH = 600;

    /** Качество WebP для thumbnail. */
    public const THUMB_QUALITY = 78;

    /**
     * Максимальная площадь изображения в пикселях, которую обрабатывает GD.
     *
     * Защита от decompression bomb: файл может быть мал по весу, но огромен по
     * разрешению (например 30000×30000), и GD развернёт его в RAM (~4 байта/px),
     * положив воркер по OOM. Свыше порога изображение сохраняется без обработки.
     */
    public const MAX_PIXELS = 40_000_000; // 40 Мп

    /**
     * Оптимизирует UploadedFile и сохраняет на диск public.
     *
     * @param  UploadedFile  $file  Загруженный файл
     * @param  string  $directory  Каталог в storage/public (например 'media' или 'avatars')
     * @param  int  $maxWidth  Максимальная ширина в px
     * @param  int  $quality  Качество WebP (0-100)
     * @return string Путь относительно диска public
     */
    public static function store(UploadedFile $file, string $directory, int $maxWidth = 1920, int $quality = 85): string
    {
        if (! self::gdCanEncodeWebp()) {
            return $file->store($directory, 'public');
        }

        $image = self::createFromFile($file->getPathname(), $file->getMimeType());

        if (! $image) {
            return $file->store($directory, 'public');
        }

        return self::processAndSave($image, $directory, $maxWidth, $quality);
    }

    /**
     * Оптимизирует файл уже находящийся на диске.
     * Используется в UploadMedia job.
     *
     * @param  string  $sourcePath  Путь к файлу на $sourceDisk
     * @param  string  $sourceDisk  Диск-источник ('local', 'public')
     * @param  string  $directory  Каталог назначения на диске public
     * @return string Путь относительно диска public
     */
    public static function storeFromDisk(
        string $sourcePath,
        string $sourceDisk,
        string $directory,
        int $maxWidth = 1920,
        int $quality = 85,
    ): string {
        $fullSourcePath = Storage::disk($sourceDisk)->path($sourcePath);

        if (! self::gdCanEncodeWebp() || ! file_exists($fullSourcePath)) {
            return self::copyWithoutProcessing($sourcePath, $sourceDisk, $directory);
        }

        $mime = mime_content_type($fullSourcePath);
        $image = self::createFromFile($fullSourcePath, $mime);

        if (! $image) {
            return self::copyWithoutProcessing($sourcePath, $sourceDisk, $directory);
        }

        return self::processAndSave($image, $directory, $maxWidth, $quality);
    }

    /**
     * Возвращает путь к thumbnail по пути к оригиналу.
     *
     * Пример: media/abc.webp → media/abc.thumb.webp
     * Если расширение не .webp (fallback без GD) — возвращает оригинал.
     */
    public static function thumbPath(string $path): string
    {
        if (! str_ends_with($path, '.webp')) {
            return $path;
        }

        return substr($path, 0, -5).'.thumb.webp';
    }

    /**
     * Генерирует thumbnail для уже сохранённого файла.
     * Используется backfill-командой для старых фото.
     *
     * @return bool true если thumb создан или уже существовал, false если не удалось
     */
    public static function generateThumbFor(string $path): bool
    {
        if (! str_ends_with($path, '.webp') || ! function_exists('imagecreatefromwebp')) {
            return false;
        }

        $disk = Storage::disk('public');
        $thumbRelative = self::thumbPath($path);

        if ($disk->exists($thumbRelative)) {
            return true;
        }

        if (! $disk->exists($path)) {
            return false;
        }

        $fullSource = $disk->path($path);
        $image = @imagecreatefromwebp($fullSource);

        if (! $image) {
            return false;
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);

        $thumb = self::makeThumb($image, self::THUMB_MAX_WIDTH);
        imagedestroy($image);

        // Источник уже ≤ THUMB_MAX_WIDTH — thumb не нужен, fallback вернёт оригинал.
        if (! $thumb) {
            return true;
        }

        self::writeWebp($thumb, $thumbRelative, self::THUMB_QUALITY);
        imagedestroy($thumb);

        return true;
    }

    /**
     * Общий пайплайн: ресайз основной версии, запись WebP, генерация thumbnail.
     */
    private static function processAndSave(\GdImage $image, string $directory, int $maxWidth, int $quality): string
    {
        $image = self::resize($image, $maxWidth);

        $baseName = Str::random(32);
        $filename = $directory.'/'.$baseName.'.webp';

        self::writeWebp($image, $filename, $quality);

        $thumb = self::makeThumb($image, self::THUMB_MAX_WIDTH);
        if ($thumb) {
            self::writeWebp($thumb, $directory.'/'.$baseName.'.thumb.webp', self::THUMB_QUALITY);
            imagedestroy($thumb);
        }

        imagedestroy($image);

        return $filename;
    }

    /**
     * Фолбэк: копирование файла без обработки (когда GD недоступен или формат неподдерживаем).
     *
     * Стримом, а не через ->get(): не тянем весь оригинал в память.
     * writeStream поток за нами не закрывает — закрываем сами (как Laravel в putFileAs).
     */
    private static function copyWithoutProcessing(string $sourcePath, string $sourceDisk, string $directory): string
    {
        $ext = pathinfo($sourcePath, PATHINFO_EXTENSION);
        $filename = $directory.'/'.Str::random(32).'.'.$ext;

        $stream = Storage::disk($sourceDisk)->readStream($sourcePath);
        Storage::disk('public')->writeStream($filename, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $filename;
    }

    /**
     * Запись GdImage как WebP в $path на диске public.
     */
    private static function writeWebp(\GdImage $image, string $path, int $quality): void
    {
        $fullPath = Storage::disk('public')->path($path);

        $dir = dirname($fullPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        imagewebp($image, $fullPath, $quality);
    }

    /**
     * Доступен ли GD с тем, что реально нужно пайплайну: декодер JPEG и энкодер WebP.
     * Если чего-то нет — вызывающий уходит на безопасный фолбэк (сохранение оригинала без обработки).
     */
    private static function gdCanEncodeWebp(): bool
    {
        return function_exists('imagewebp') && function_exists('imagecreatefromjpeg');
    }

    /**
     * Создаёт GdImage из файла по пути.
     */
    private static function createFromFile(string $path, string $mime): ?\GdImage
    {
        // Декодируем только в пределах лимита размерности (см. MAX_PIXELS).
        // Возврат null уводит вызывающего на безопасный фолбэк (копия без обработки).
        if (self::exceedsPixelLimit($path)) {
            return null;
        }

        return match ($mime) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path),
            'image/png' => self::createFromPng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => null,
        } ?: null;
    }

    /**
     * Превышает ли изображение лимит размерности (MAX_PIXELS).
     *
     * Читает только заголовок через getimagesize() — без декодирования растра,
     * поэтому безопасно вызывать до GD даже на «бомбе».
     */
    private static function exceedsPixelLimit(string $path): bool
    {
        $info = @getimagesize($path);

        if ($info === false) {
            return false; // размер не прочитался — пусть решает декодер
        }

        return $info[0] * $info[1] > self::MAX_PIXELS;
    }

    /**
     * PNG с сохранением прозрачности.
     */
    private static function createFromPng(string $path): ?\GdImage
    {
        $image = @imagecreatefrompng($path);
        if (! $image) {
            return null;
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);

        return $image;
    }

    /**
     * Ресайз если шире maxWidth, сохраняя пропорции. Оригинал уничтожается.
     */
    private static function resize(\GdImage $image, int $maxWidth): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width <= $maxWidth) {
            return $image;
        }

        $ratio = $maxWidth / $width;
        $newHeight = (int) ($height * $ratio);

        $resized = imagecreatetruecolor($maxWidth, $newHeight);

        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $maxWidth, $newHeight, $width, $height);
        imagedestroy($image);

        return $resized;
    }

    /**
     * Создаёт уменьшенную копию БЕЗ уничтожения исходника.
     * Возвращает null, если исходник уже не шире thumbnail (thumb не нужен).
     */
    private static function makeThumb(\GdImage $source, int $maxWidth): ?\GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);

        if ($width <= $maxWidth) {
            return null;
        }

        $ratio = $maxWidth / $width;
        $newHeight = (int) ($height * $ratio);

        $thumb = imagecreatetruecolor($maxWidth, $newHeight);

        imagealphablending($thumb, false);
        imagesavealpha($thumb, true);

        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $maxWidth, $newHeight, $width, $height);

        return $thumb;
    }
}
