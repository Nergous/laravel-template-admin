<?php

namespace App\Support\Media;

/**
 * The largest image area in pixels GD is allowed to process.
 *
 * Guards against decompression bombs: a file may be small in bytes but huge
 * in resolution (e.g. 30000×30000), and GD would expand it in memory
 * (~4 bytes per pixel), killing the worker with an out-of-memory error. Uploads
 * reject such files (App\Rules\ImageWithinPixelLimit), and ImageOptimizer
 * stores whatever still gets through without processing.
 */
final class PixelLimit
{
    public const MAX_PIXELS = 40_000_000; // 40 MP

    /**
     * Whether the image exceeds the limit. Reads only the header via
     * getimagesize(), without decoding the raster, so it is safe even for a bomb.
     * When the size cannot be read, the decoder decides.
     */
    public static function exceeds(string $path): bool
    {
        $info = @getimagesize($path);

        return $info !== false && $info[0] * $info[1] > self::MAX_PIXELS;
    }

    /** The limit in megapixels, for user messages. */
    public static function megapixels(): int
    {
        return intdiv(self::MAX_PIXELS, 1_000_000);
    }
}
