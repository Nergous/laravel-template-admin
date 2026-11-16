<?php

namespace App\Services\Media;

use App\Enums\ImageFormat;
use App\Models\Media;
use App\Support\Media\GdImages;
use App\Support\Media\ImagePaths;
use GdImage;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Media library image optimization:
 * - conversion to WebP, or to AVIF when media.image_format asks for it and GD
 *   can encode it;
 * - downscaling to MAX_WIDTH;
 * - a thumbnail for cards and feeds (ImagePaths::THUMB_WIDTH);
 * - narrower responsive copies for srcset (media.variant_widths).
 *
 * Without GD, or when the image cannot be decoded (including images over
 * PixelLimit), the file is stored as is.
 *
 * Derived files sit next to the original (ImagePaths): media/abc.webp →
 * media/abc.thumb.webp (thumbnail) and media/abc.w960.webp (responsive copy).
 * The media disk may be remote: files are read through local copies and
 * written as streams (MediaStorage); pixel operations live in GdImages.
 */
class ImageOptimizer
{
    /** Maximum width of the main version, px. */
    public const MAX_WIDTH = 1920;

    /** Quality of the main version and the responsive copies (0–100). */
    public const QUALITY = 85;

    /** Thumbnail quality. */
    public const THUMB_QUALITY = 78;

    /** @param  array<int, int|string>  $variantWidths */
    public function __construct(
        private readonly MediaStorage $storage,
        #[Config('media.image_format', 'webp')] private readonly ?string $format,
        #[Config('media.variant_widths', [])] private readonly array $variantWidths,
    ) {}

    /**
     * Optimizes a file stored on $sourceDisk and writes the result to
     * $directory on the media disk.
     *
     * @return array{filename: string, variants: list<int>} variants — widths of the responsive copies
     */
    public function optimizeFromDisk(string $sourcePath, string $sourceDisk, string $directory): array
    {
        $image = $this->gdCanProcess()
            ? $this->storage->withLocalCopy($sourceDisk, $sourcePath, GdImages::decode(...))
            : null;

        if ($image === null) {
            return ['filename' => $this->storage->copy($sourceDisk, $sourcePath, $directory), 'variants' => []];
        }

        return $this->save($image, $directory);
    }

    /**
     * Crops an image on the media disk to an area given as fractions of width and
     * height (0..1) and stores the result as a new file with its own thumbnail and
     * responsive copies. The source files are left untouched.
     *
     * @param  array{x: float, y: float, width: float, height: float}  $area
     * @return array{filename: string, variants: list<int>}
     *
     * @throws RuntimeException No GD, or the image cannot be decoded.
     */
    public function crop(string $path, array $area): array
    {
        if (! $this->gdCanProcess()) {
            throw new RuntimeException('Для обрезки нужно PHP-расширение GD.');
        }

        $image = $this->storage->withLocalCopy(Media::diskName(), $path, GdImages::decode(...));
        if ($image === null) {
            throw new RuntimeException('Этот формат изображения нельзя обрезать.');
        }

        $cropped = GdImages::crop($image, $area);

        return $this->save($cropped, dirname($path));
    }

    /**
     * Shared pipeline: downscale the main version, write it, then the narrower
     * responsive copies and the thumbnail.
     *
     * @return array{filename: string, variants: list<int>}
     */
    private function save(GdImage $image, string $directory): array
    {
        $image = GdImages::downscale($image, self::MAX_WIDTH) ?? $image;
        $format = $this->format();

        $filename = $directory.'/'.Str::random(32).'.'.$format->extension();
        $this->write($image, $filename, $format, self::QUALITY);

        $variants = [];
        foreach ($this->variantWidths() as $width) {
            if ($copy = GdImages::downscale($image, $width)) {
                $this->write($copy, ImagePaths::variant($filename, $width), $format, self::QUALITY);
                $variants[] = $width;
            }
        }

        if ($thumb = GdImages::downscale($image, ImagePaths::THUMB_WIDTH)) {
            $this->write($thumb, ImagePaths::thumb($filename), $format, self::THUMB_QUALITY);
        }

        return ['filename' => $filename, 'variants' => $variants];
    }

    /**
     * Encodes the image into a temporary local file and streams it to the media
     * disk.
     *
     * @throws RuntimeException When encoding or writing fails.
     */
    private function write(GdImage $image, string $path, ImageFormat $format, int $quality): void
    {
        $temp = tmpfile();
        if ($temp === false) {
            throw new RuntimeException('Не удалось создать временный файл изображения.');
        }

        try {
            if (! GdImages::encode($image, $temp, $format, $quality)) {
                throw new RuntimeException('Не удалось закодировать изображение: '.$path);
            }

            rewind($temp);
            if (! $this->storage->disk()->writeStream($path, $temp)) {
                throw new RuntimeException('Не удалось записать изображение на диск медиатеки.');
            }
        } finally {
            fclose($temp);
        }
    }

    /**
     * Whether GD has what the pipeline needs: a JPEG decoder and a WebP encoder
     * (the fallback output format when AVIF is unavailable).
     */
    private function gdCanProcess(): bool
    {
        return function_exists('imagewebp') && function_exists('imagecreatefromjpeg');
    }

    /** Output format: AVIF when configured and GD is built with AVIF, otherwise WebP. */
    private function format(): ImageFormat
    {
        return $this->format === ImageFormat::Avif->value && $this->canEncodeAvif() ? ImageFormat::Avif : ImageFormat::Webp;
    }

    private function canEncodeAvif(): bool
    {
        return function_exists('imageavif') && (gd_info()['AVIF Support'] ?? false) === true;
    }

    /** @return list<int> Responsive copy widths in ascending order, wider than the thumbnail. */
    private function variantWidths(): array
    {
        $widths = array_map('intval', $this->variantWidths);
        $widths = array_values(array_unique(array_filter($widths, fn (int $w) => $w > ImagePaths::THUMB_WIDTH)));
        sort($widths);

        return $widths;
    }
}
