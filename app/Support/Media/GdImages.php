<?php

namespace App\Support\Media;

use App\Enums\ImageFormat;
use GdImage;

/**
 * GD operations on in-memory images: decoding with EXIF rotation,
 * downscaling, cropping and encoding to WebP or AVIF. PNG and WebP
 * transparency is kept. Disk I/O is the caller's job (ImageOptimizer).
 */
final class GdImages
{
    /**
     * Decodes a local file. null — unsupported format, a broken file or larger
     * than PixelLimit: the caller takes the safe path (an unprocessed copy).
     */
    public static function decode(string $path): ?GdImage
    {
        if (PixelLimit::exceeds($path)) {
            return null;
        }

        $mime = mime_content_type($path) ?: '';
        $image = match ($mime) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            'image/avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : false,
            default => false,
        } ?: null;

        if ($image === null) {
            return null;
        }

        if ($mime === 'image/png') {
            imagealphablending($image, true);
            imagesavealpha($image, true);
        }

        // WebP drops the EXIF tag, so the JPEG orientation is applied before encoding.
        return in_array($mime, ['image/jpeg', 'image/jpg'], true)
            ? self::orient($image, self::exifOrientation($path))
            : $image;
    }

    /**
     * A downscaled copy $maxWidth wide with the same proportions; the source is
     * left untouched. null — the source is not wider anyway.
     */
    public static function downscale(GdImage $source, int $maxWidth): ?GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);

        if ($width <= $maxWidth) {
            return null;
        }

        $copy = self::canvas($maxWidth, (int) ($height * $maxWidth / $width));
        imagecopyresampled($copy, $source, 0, 0, 0, 0, imagesx($copy), imagesy($copy), $width, $height);

        return $copy;
    }

    /**
     * A new image from an area of the source given as fractions of width and height (0..1).
     *
     * @param  array{x: float, y: float, width: float, height: float}  $area
     */
    public static function crop(GdImage $source, array $area): GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $x = (int) round(max(0, min(1, $area['x'])) * $width);
        $y = (int) round(max(0, min(1, $area['y'])) * $height);
        $cropWidth = max(1, min($width - $x, (int) round($area['width'] * $width)));
        $cropHeight = max(1, min($height - $y, (int) round($area['height'] * $height)));

        $cropped = self::canvas($cropWidth, $cropHeight);
        imagecopy($cropped, $source, 0, 0, $x, $y, $cropWidth, $cropHeight);

        return $cropped;
    }

    /**
     * Encodes the image to a stream in $format.
     *
     * @param  resource  $stream
     */
    public static function encode(GdImage $image, $stream, ImageFormat $format, int $quality): bool
    {
        return match ($format) {
            ImageFormat::Avif => imageavif($image, $stream, $quality),
            ImageFormat::Webp => imagewebp($image, $stream, $quality),
        };
    }

    /**
     * Applies the EXIF orientation (1–8) so the pixels stand upright. GD
     * rotates counter-clockwise for positive angles.
     */
    private static function orient(GdImage $image, int $orientation): GdImage
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

        $rotated = $angle !== 0 ? imagerotate($image, $angle, 0) : false;

        return $rotated ?: $image;
    }

    /** JPEG orientation from EXIF; 1 (upright) without the exif extension or the tag. */
    private static function exifOrientation(string $path): int
    {
        if (! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($path, 'IFD0', true, false);
        $orientation = $exif['IFD0']['Orientation'] ?? $exif['Orientation'] ?? 1;

        return is_numeric($orientation) ? (int) $orientation : 1;
    }

    /** An empty true-color image with transparency. */
    private static function canvas(int $width, int $height): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        return $image;
    }
}
