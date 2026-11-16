<?php

namespace App\Support\Media;

/**
 * Media library folder paths. A folder is a path of names joined by "/" ("Banners/2026");
 * files reference a folder by its full path (media.folder), and every folder has
 * a media_folders row with that path in name. Input names and paths are
 * validated by App\Rules\SafeFolderName and SafeFolderPath.
 */
final class MediaFolderPath
{
    /** The longest path (size of the media.folder and media_folders.name columns). */
    public const MAX = 255;

    /** The longest name of a single folder. */
    public const NAME_MAX = 100;

    /** Trims every part and drops empty ones: " A / /B " → "A/B"; nothing left — null. */
    public static function normalize(mixed $value): ?string
    {
        $parts = array_filter(
            array_map('trim', explode('/', (string) $value)),
            fn (string $part) => $part !== '',
        );

        return $parts === [] ? null : implode('/', $parts);
    }

    /** "A/B/C" → "A/B"; a top-level folder has no parent. */
    public static function parent(string $path): ?string
    {
        $pos = mb_strrpos($path, '/');

        return $pos === false ? null : mb_substr($path, 0, $pos);
    }

    public static function join(?string $parent, string $name): string
    {
        return $parent === null || $parent === '' ? $name : $parent.'/'.$name;
    }

    /**
     * The path and every folder above it, outermost first: "A/B" → ["A", "A/B"].
     *
     * @return list<string>
     */
    public static function lineage(string $path): array
    {
        $lineage = [];
        $current = null;
        foreach (explode('/', $path) as $part) {
            $current = self::join($current, $part);
            $lineage[] = $current;
        }

        return $lineage;
    }

    /** Whether $path equals $folder or lies inside it (case-insensitive). */
    public static function within(string $path, string $folder): bool
    {
        $path = mb_strtolower($path);
        $folder = mb_strtolower($folder);

        return $path === $folder || str_starts_with($path, $folder.'/');
    }
}
