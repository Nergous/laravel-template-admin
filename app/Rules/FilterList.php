<?php

namespace App\Rules;

use App\Support\FilterValues;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A multi-value list filter in the format read by App\Support\FilterValues:
 * a comma-separated string or an array. Every value must be allowed; an empty
 * value passes. Used where filters select records for an action (bulk actions
 * on all matching) so that a typo cannot widen the selection.
 */
final class FilterList implements ValidationRule
{
    /**
     * @param  list<string>|null  $allowed  Allowed values; null — any value up to $maxLength
     */
    private function __construct(
        private readonly ?array $allowed,
        private readonly bool $ids,
        private readonly int $maxLength,
    ) {}

    /** @param  list<string>  $allowed */
    public static function of(array $allowed): self
    {
        return new self($allowed, false, 255);
    }

    public static function ids(): self
    {
        return new self(null, true, 20);
    }

    /**
     * Any strings up to 255 characters: for filters where an unknown value
     * simply finds nothing (e.g. a deleted role).
     */
    public static function any(): self
    {
        return new self(null, false, 255);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || $value === []) {
            return;
        }

        // A JSON body or a test sends a single id as a number.
        $items = match (true) {
            is_array($value) => $value,
            is_string($value), is_int($value) => explode(',', (string) $value),
            default => null,
        };

        if ($items === null || count($items) > FilterValues::MAX) {
            $fail('Некорректный фильтр');

            return;
        }

        foreach ($items as $item) {
            $item = is_scalar($item) ? trim((string) $item) : null;

            if ($item === '') {
                continue;
            }

            if (
                $item === null
                || mb_strlen($item) > $this->maxLength
                || ($this->ids && filter_var($item, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false)
                || ($this->allowed !== null && ! in_array($item, $this->allowed, true))
            ) {
                $fail('Фильтр содержит недопустимое значение');

                return;
            }
        }
    }
}
