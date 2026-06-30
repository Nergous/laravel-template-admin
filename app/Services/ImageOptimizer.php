<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Image optimization on upload:
 * - Conversion to WebP (30-50% size reduction)
 * - Resize to a maximum width
 * - Thumbnail generation for cards/feeds
 * - Configurable compression quality
 *
 * If GD is unavailable — stores the original without processing.
 */
class ImageOptimizer
{
    /** Thumbnail width (enough for cards up to ~300px with 2x retina). */
    public const THUMB_MAX_WIDTH = 600;

    /** WebP quality for thumbnails. */
    public const THUMB_QUALITY = 78;

    /**
     * Maximum image area in pixels that GD will process.
     *
     * Protection against a decompression bomb: a file can be small in bytes but
     * huge in resolution (e.g. 30000x30000), and GD would expand it in RAM
     * (~4 bytes/px), killing the worker with OOM. Above the threshold the image
     * is stored without processing.
     */
    public const MAX_PIXELS = 40_000_000; // 40 MP

    /**
     * Optimizes an UploadedFile and stores it on the public disk.
     *
     * @param  UploadedFile  $file  The uploaded file
     * @param  string  $directory  Directory in storage/public (e.g. 'media' or 'avatars')
     * @param  int  $maxWidth  Maximum width in px
     * @param  int  $quality  WebP quality (0-100)
     * @return string Path relative to the public disk
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
     * Optimizes a file already present on a disk.
     * Used in the UploadMedia job.
     *
     * @param  string  $sourcePath  Path to the file on $sourceDisk
     * @param  string  $sourceDisk  Source disk ('local', 'public')
     * @param  string  $directory  Destination directory on the public disk
     * @return string Path relative to the public disk
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
     * Returns the thumbnail path given the path to the original.
     *
     * Example: media/abc.webp → media/abc.thumb.webp
     * If the extension is not .webp (fallback without GD) — returns the original.
     */
    public static function thumbPath(string $path): string
    {
        if (! str_ends_with($path, '.webp')) {
            return $path;
        }

        return substr($path, 0, -5).'.thumb.webp';
    }

    /**
     * Generates a thumbnail for an already-stored file.
     * Used by the backfill command for old photos.
     *
     * @return bool true if the thumb was created or already existed, false if it failed
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

        // Source is already ≤ THUMB_MAX_WIDTH — no thumb needed, fallback returns the original.
        if (! $thumb) {
            return true;
        }

        self::writeWebp($thumb, $thumbRelative, self::THUMB_QUALITY);
        imagedestroy($thumb);

        return true;
    }

    /**
     * Shared pipeline: resize the main version, write WebP, generate the thumbnail.
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
     * Fallback: copying the file without processing (when GD is unavailable or the format is unsupported).
     *
     * Via a stream rather than ->get(): we don't pull the whole original into memory.
     * writeStream does not close the stream for us — we close it ourselves (as Laravel does in putFileAs).
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
     * Writes a GdImage as WebP to $path on the public disk.
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
     * Whether GD is available with what the pipeline actually needs: a JPEG decoder and a WebP encoder.
     * If something is missing — the caller falls back to the safe path (storing the original without processing).
     */
    private static function gdCanEncodeWebp(): bool
    {
        return function_exists('imagewebp') && function_exists('imagecreatefromjpeg');
    }

    /**
     * Creates a GdImage from a file at the given path.
     */
    private static function createFromFile(string $path, string $mime): ?\GdImage
    {
        // Decode only within the dimension limit (see MAX_PIXELS).
        // Returning null sends the caller to the safe fallback (a copy without processing).
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
     * Whether the image exceeds the dimension limit (MAX_PIXELS).
     *
     * Reads only the header via getimagesize() — without decoding the raster,
     * so it's safe to call before GD even on a "bomb".
     */
    private static function exceedsPixelLimit(string $path): bool
    {
        $info = @getimagesize($path);

        if ($info === false) {
            return false; // size couldn't be read — let the decoder decide
        }

        return $info[0] * $info[1] > self::MAX_PIXELS;
    }

    /**
     * PNG with transparency preserved.
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
     * Resizes if wider than maxWidth, preserving the aspect ratio. The original is destroyed.
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
     * Creates a downscaled copy WITHOUT destroying the source.
     * Returns null if the source is already no wider than the thumbnail (no thumb needed).
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
