<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Media folder paths. A folder is a path of names joined by "/" ("Баннеры/2026");
 * files point at their folder by the full path (media.folder), and every folder
 * has a media_folders row named by its path.
 */
final class MediaFolderPath
{
    /** Longest path (the media.folder / media_folders.name column size). */
    public const MAX = 255;

    /** Longest name of a single folder. */
    public const NAME_MAX = 100;

    /** One folder name: no slashes or control characters. */
    public const NAME_RULE = 'not_regex:/[\/\\\\\x00-\x1F\x7F]/';

    /** A path: no backslashes or control characters, no "." or ".." parts. */
    public const PATH_RULES = [
        'not_regex:/[\\\\\x00-\x1F\x7F]/',
        'not_regex:/(^|\/)\.{1,2}(\/|$)/',
    ];

    /** Trims every part and drops empty ones: " A / /B " → "A/B"; nothing left → null. */
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

    /** Whether $path is $folder itself or lies inside it. */
    public static function within(string $path, string $folder): bool
    {
        return mb_strtolower($path) === mb_strtolower($folder)
            || str_starts_with(mb_strtolower($path), mb_strtolower($folder).'/');
    }

    /**
     * Narrows a query to the folder and everything inside it. substr() keeps
     * names with % or _ literal, unlike LIKE.
     */
    public static function scope(Builder|QueryBuilder $query, string $column, string $folder): Builder|QueryBuilder
    {
        $prefix = $folder.'/';

        return $query->where(fn ($q) => $q
            ->where($column, $folder)
            ->orWhereRaw("substr({$column}, 1, ?) = ?", [mb_strlen($prefix), $prefix]));
    }
}
