<?php

namespace App\Support\Media;

/**
 * Paths of the files ImageOptimizer derives from a processed image. They sit
 * next to the original and exist only for WebP and AVIF; any other file
 * (stored unprocessed) maps to itself.
 */
final class ImagePaths
{
    /** Thumbnail width (enough for cards up to ~300px on 2x screens). */
    public const THUMB_WIDTH = 600;

    /** media/abc.webp → media/abc.thumb.webp (the same for .avif). */
    public static function thumb(string $path): string
    {
        return preg_replace('/\.(webp|avif)$/', '.thumb.$1', $path) ?? $path;
    }

    /** A responsive copy of the given width: media/abc.webp → media/abc.w480.webp. */
    public static function variant(string $path, int $width): string
    {
        return preg_replace('/\.(webp|avif)$/', ".w{$width}.$1", $path) ?? $path;
    }
}
