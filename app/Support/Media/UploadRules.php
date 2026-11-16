<?php

namespace App\Support\Media;

use App\Enums\MediaType;

/**
 * Media library upload limits shared by the upload and replace requests and
 * by the upload widgets (UploadRules in
 * resources/js/admin/composables/useMediaUpload.ts), which check files before
 * sending them. Allowed extensions come from MediaType::extensions().
 */
final class UploadRules
{
    /** Maximum size of one file in kilobytes (~50 MB). */
    public const MAX_SIZE_KB = 51200;

    /** Maximum number of files in one upload. */
    public const MAX_FILES = 10;

    /**
     * Allowed extensions: only those of $type when given, otherwise all.
     *
     * @return list<string>
     */
    public static function extensions(?MediaType $type = null): array
    {
        if ($type !== null) {
            return $type->extensions();
        }

        return array_merge(...array_map(fn (MediaType $type) => $type->extensions(), MediaType::cases()));
    }

    /** "50 МБ" for validation messages. */
    public static function maxSizeLabel(): string
    {
        return intdiv(self::MAX_SIZE_KB, 1024).' МБ';
    }

    /**
     * Limits for the upload widgets. With a type — only its extensions (a type
     * without uploadable formats gets an empty list).
     *
     * @return array{extensions: list<string>, max_size_kb: int, max_files: int}
     */
    public static function toArray(?MediaType $type = null): array
    {
        return [
            'extensions' => self::extensions($type),
            'max_size_kb' => self::MAX_SIZE_KB,
            'max_files' => self::MAX_FILES,
        ];
    }
}
