<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Image optimization on upload:
 * - Conversion to WebP, or AVIF when config('media.image_format') asks for it
 *   and GD can encode it
 * - Resize to a maximum width
 * - Thumbnail generation for cards/feeds
 * - Responsive copies of smaller widths for srcset (config('media.variant_widths'))
 * - Configurable compression quality
 *
 * If GD is unavailable — stores the original without processing.
 *
 * Derived files sit next to the original: media/abc.webp → media/abc.thumb.webp
 * (thumbnail) and media/abc.w480.webp (responsive copy).
 *
 * Output goes to the media disk (config('media.disk')), which may be remote
 * (S3): files are read through streams into local temp copies for GD and
 * written back with writeStream — never via ->path() on the media disk.
 * JPEG EXIF orientation is applied before encoding (WebP drops the EXIF tag).
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
     * Optimizes an UploadedFile and stores it on the configured media disk.
     *
     * @param  UploadedFile  $file  The uploaded file
     * @param  string  $directory  Destination directory (e.g. 'media' or 'avatars')
     * @param  int  $maxWidth  Maximum width in px
     * @param  int  $quality  WebP quality (0-100)
     * @return string Path relative to the media disk
     */
    public static function store(UploadedFile $file, string $directory, int $maxWidth = 1920, int $quality = 85): string
    {
        if (! self::gdCanProcess()) {
            return $file->store($directory, self::mediaDisk());
        }

        $image = self::createFromFile($file->getPathname(), $file->getMimeType());

        if (! $image) {
            return $file->store($directory, self::mediaDisk());
        }

        return self::processAndSave($image, $directory, $maxWidth, $quality)['filename'];
    }

    /**
     * Optimizes a file already present on a disk.
     *
     * @param  string  $sourcePath  Path to the file on $sourceDisk
     * @param  string  $sourceDisk  Source disk ('local', 'public')
     * @param  string  $directory  Destination directory on the media disk
     * @return string Path relative to the media disk
     */
    public static function storeFromDisk(
        string $sourcePath,
        string $sourceDisk,
        string $directory,
        int $maxWidth = 1920,
        int $quality = 85,
    ): string {
        return self::optimizeFromDisk($sourcePath, $sourceDisk, $directory, $maxWidth, $quality)['filename'];
    }

    /**
     * Optimizes a file already present on a disk and reports the responsive
     * copies it produced. Used in the UploadMedia job.
     *
     * @return array{filename: string, variants: list<int>} variants — widths of the responsive copies
     */
    public static function optimizeFromDisk(
        string $sourcePath,
        string $sourceDisk,
        string $directory,
        int $maxWidth = 1920,
        int $quality = 85,
    ): array {
        if (! self::gdCanProcess() || ! Storage::disk($sourceDisk)->exists($sourcePath)) {
            return ['filename' => self::copyWithoutProcessing($sourcePath, $sourceDisk, $directory), 'variants' => []];
        }

        $image = self::withLocalCopy($sourceDisk, $sourcePath, function (string $localPath) {
            $mime = mime_content_type($localPath) ?: '';

            return self::createFromFile($localPath, $mime);
        });

        if (! $image) {
            return ['filename' => self::copyWithoutProcessing($sourcePath, $sourceDisk, $directory), 'variants' => []];
        }

        return self::processAndSave($image, $directory, $maxWidth, $quality);
    }

    /**
     * Crops a stored image to a normalized area (0..1 fractions of the width and
     * height) and stores the result as a new optimized file with its own
     * thumbnail and responsive copies. The source files are left untouched.
     *
     * @param  array{x: float, y: float, width: float, height: float}  $area
     * @return array{filename: string, variants: list<int>}
     *
     * @throws \RuntimeException When the image cannot be decoded or GD is missing.
     */
    public static function crop(string $path, array $area, int $maxWidth = 1920, int $quality = 85): array
    {
        if (! self::gdCanProcess()) {
            throw new \RuntimeException('Для обрезки нужно PHP-расширение GD.');
        }

        $image = self::withLocalCopy(self::mediaDisk(), $path, function (string $localPath) {
            return self::createFromFile($localPath, mime_content_type($localPath) ?: '');
        });

        if (! $image) {
            throw new \RuntimeException('Этот формат изображения нельзя обрезать.');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $rect = [
            'x' => (int) round(max(0, min(1, $area['x'])) * $width),
            'y' => (int) round(max(0, min(1, $area['y'])) * $height),
        ];
        $rect['width'] = max(1, min($width - $rect['x'], (int) round($area['width'] * $width)));
        $rect['height'] = max(1, min($height - $rect['y'], (int) round($area['height'] * $height)));

        $cropped = self::cropPreservingAlpha($image, $rect);
        imagedestroy($image);

        return self::processAndSave($cropped, dirname($path), $maxWidth, $quality);
    }

    /**
     * Returns the thumbnail path given the path to the original.
     *
     * Example: media/abc.webp → media/abc.thumb.webp (and .avif likewise).
     * For other extensions (fallback without GD) — returns the original.
     */
    public static function thumbPath(string $path): string
    {
        return preg_replace('/\.(webp|avif)$/', '.thumb.$1', $path) ?? $path;
    }

    /** Path of the responsive copy of the given width: media/abc.webp → media/abc.w480.webp. */
    public static function variantPath(string $path, int $width): string
    {
        return preg_replace('/\.(webp|avif)$/', ".w{$width}.$1", $path) ?? $path;
    }

    /** Whether the path is a thumbnail or a responsive copy rather than an original. */
    public static function isDerivedPath(string $path): bool
    {
        return preg_match('/\.(thumb|w\d+)\.(webp|avif)$/', $path) === 1;
    }

    /**
     * Generates a thumbnail for an already-stored file.
     * Used by the backfill command for old photos.
     *
     * @return bool true if the thumb was created or already existed, false if it failed
     */
    public static function generateThumbFor(string $path): bool
    {
        $mime = match (true) {
            str_ends_with($path, '.webp') && function_exists('imagecreatefromwebp') => 'image/webp',
            str_ends_with($path, '.avif') && function_exists('imagecreatefromavif') => 'image/avif',
            default => null,
        };

        if ($mime === null) {
            return false;
        }

        $disk = Storage::disk(self::mediaDisk());
        $thumbRelative = self::thumbPath($path);

        if ($disk->exists($thumbRelative)) {
            return true;
        }

        if (! $disk->exists($path)) {
            return false;
        }

        $image = self::withLocalCopy(
            self::mediaDisk(),
            $path,
            fn (string $localPath) => self::createFromFile($localPath, $mime),
        );

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

        self::writeImage($thumb, $thumbRelative, self::THUMB_QUALITY);
        imagedestroy($thumb);

        return true;
    }

    /**
     * Reads image dimensions without assuming that the media disk is local.
     *
     * @return array{width:int,height:int}|null
     */
    public static function dimensions(string $path): ?array
    {
        $disk = self::mediaDisk();
        if (! Storage::disk($disk)->exists($path)) {
            return null;
        }

        return self::withLocalCopy($disk, $path, function (string $localPath) {
            $info = @getimagesize($localPath);

            return $info ? ['width' => $info[0], 'height' => $info[1]] : null;
        });
    }

    /**
     * Shared pipeline: resize the main version, write it, then the responsive
     * copies narrower than it and the thumbnail.
     *
     * @return array{filename: string, variants: list<int>}
     */
    private static function processAndSave(\GdImage $image, string $directory, int $maxWidth, int $quality): array
    {
        $image = self::resize($image, $maxWidth);

        $baseName = Str::random(32);
        $filename = $directory.'/'.$baseName.'.'.self::outputExtension();

        self::writeImage($image, $filename, $quality);

        $variants = [];
        foreach (self::variantWidths() as $width) {
            $copy = self::makeThumb($image, $width);
            if ($copy) {
                self::writeImage($copy, self::variantPath($filename, $width), $quality);
                imagedestroy($copy);
                $variants[] = $width;
            }
        }

        $thumb = self::makeThumb($image, self::THUMB_MAX_WIDTH);
        if ($thumb) {
            self::writeImage($thumb, self::thumbPath($filename), self::THUMB_QUALITY);
            imagedestroy($thumb);
        }

        imagedestroy($image);

        return ['filename' => $filename, 'variants' => $variants];
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
        Storage::disk(self::mediaDisk())->writeStream($filename, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $filename;
    }

    /**
     * Writes a GdImage to $path on the media disk through a local stream; the
     * format follows the extension (.avif — AVIF, otherwise WebP).
     */
    private static function writeImage(\GdImage $image, string $path, int $quality): void
    {
        $temp = tmpfile();
        if ($temp === false) {
            throw new \RuntimeException('Unable to create a temporary image file.');
        }

        try {
            $encoded = str_ends_with($path, '.avif')
                ? imageavif($image, $temp, $quality)
                : imagewebp($image, $temp, $quality);

            if (! $encoded) {
                throw new \RuntimeException('Unable to encode image: '.$path);
            }

            rewind($temp);
            if (! Storage::disk(self::mediaDisk())->writeStream($path, $temp)) {
                throw new \RuntimeException('Unable to write image to the media disk.');
            }
        } finally {
            fclose($temp);
        }
    }

    /**
     * Whether GD is available with what the pipeline actually needs: a JPEG decoder and a WebP encoder
     * (the fallback output format when AVIF is not available).
     * If something is missing — the caller falls back to the safe path (storing the original without processing).
     */
    private static function gdCanProcess(): bool
    {
        return function_exists('imagewebp') && function_exists('imagecreatefromjpeg');
    }

    /** Output extension: avif when configured and GD was built with AVIF support, webp otherwise. */
    public static function outputExtension(): string
    {
        return config('media.image_format') === 'avif' && self::canEncodeAvif() ? 'avif' : 'webp';
    }

    private static function canEncodeAvif(): bool
    {
        return function_exists('imageavif') && (gd_info()['AVIF Support'] ?? false) === true;
    }

    /** @return list<int> Responsive copy widths, ascending, below the main image cap. */
    private static function variantWidths(): array
    {
        $widths = array_map('intval', (array) config('media.variant_widths', []));
        $widths = array_values(array_unique(array_filter($widths, fn (int $w) => $w > self::THUMB_MAX_WIDTH)));
        sort($widths);

        return $widths;
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

        $image = match ($mime) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path),
            'image/png' => self::createFromPng($path),
            'image/webp' => @imagecreatefromwebp($path),
            'image/avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : false,
            default => null,
        } ?: null;

        if ($image && in_array($mime, ['image/jpeg', 'image/jpg'], true)) {
            $image = self::applyExifOrientation($image, self::exifOrientation($path));
        }

        return $image;
    }

    /**
     * Applies an EXIF orientation (1–8) so the pixels are upright, and returns
     * the resulting image (the source is destroyed when a new one is created).
     * GD rotates counter-clockwise for positive angles.
     */
    public static function applyExifOrientation(\GdImage $image, int $orientation): \GdImage
    {
        if (in_array($orientation, [2, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        }

        $angle = match ($orientation) {
            3 => 180,
            5, 8 => 90,
            6, 7 => -90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);
        if (! $rotated) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    /** EXIF orientation of a JPEG; 1 (upright) when exif is missing or the tag is absent. */
    private static function exifOrientation(string $path): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($path, 'IFD0', true, false);
        $orientation = $exif['IFD0']['Orientation'] ?? $exif['Orientation'] ?? 1;

        return is_numeric($orientation) ? (int) $orientation : 1;
    }

    private static function mediaDisk(): string
    {
        return (string) config('media.disk', 'public');
    }

    /**
     * Streams a file from any disk into a local temp file and passes its path to
     * $callback; the temp file is removed afterwards.
     *
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     */
    private static function withLocalCopy(string $diskName, string $path, callable $callback): mixed
    {
        $source = Storage::disk($diskName)->readStream($path);
        $temp = tmpfile();

        if (! is_resource($source) || $temp === false) {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($temp)) {
                fclose($temp);
            }

            throw new \RuntimeException('Unable to create a local media copy.');
        }

        try {
            stream_copy_to_stream($source, $temp);
            fflush($temp);
            $metadata = stream_get_meta_data($temp);

            return $callback($metadata['uri']);
        } finally {
            fclose($source);
            fclose($temp);
        }
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

    /**
     * Copies a rectangle of the source into a new image, keeping transparency.
     *
     * @param  array{x: int, y: int, width: int, height: int}  $rect
     */
    private static function cropPreservingAlpha(\GdImage $source, array $rect): \GdImage
    {
        $cropped = imagecreatetruecolor($rect['width'], $rect['height']);
        imagealphablending($cropped, false);
        imagesavealpha($cropped, true);
        imagecopy($cropped, $source, 0, 0, $rect['x'], $rect['y'], $rect['width'], $rect['height']);

        return $cropped;
    }
}
