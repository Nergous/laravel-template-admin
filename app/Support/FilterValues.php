<?php

namespace App\Support;

use BackedEnum;

/**
 * Values of a list filter that allows several choices. In the URL they are
 * one comma-separated parameter (?type=image,video); a single value
 * (?type=image) and a query-string array (?type[]=image) read the same way.
 * Choices of one filter combine with OR (whereIn), different filters with AND.
 *
 * The readers are lenient: unknown values are dropped, so an old link still
 * opens the list. Requests that act on the selection validate the same format
 * strictly via App\Rules\FilterList.
 */
final class FilterValues
{
    /** At most this many choices of one filter are read: the rest are dropped. */
    public const MAX = 50;

    /**
     * Trimmed, non-empty, unique values in their original order.
     *
     * @return list<string>
     */
    public static function strings(mixed $raw): array
    {
        $items = is_array($raw) ? $raw : explode(',', is_scalar($raw) ? (string) $raw : '');
        $values = [];

        foreach ($items as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $value = trim((string) $item);

            if ($value !== '' && ! in_array($value, $values, true)) {
                $values[] = $value;
            }

            if (count($values) === self::MAX) {
                break;
            }
        }

        return $values;
    }

    /**
     * Positive integer ids.
     *
     * @return list<int>
     */
    public static function ids(mixed $raw): array
    {
        $ids = [];

        foreach (self::strings($raw) as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($id !== false && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Cases of the backed enum that exist; unknown values are dropped.
     *
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return list<T>
     */
    public static function enums(mixed $raw, string $enum): array
    {
        return array_values(array_filter(array_map(
            fn (string $value) => $enum::tryFrom($value),
            self::strings($raw),
        )));
    }
}
