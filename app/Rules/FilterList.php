<?php

namespace App\Rules;

use App\Support\FilterValues;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A list filter of several choices, in the format App\Support\FilterValues
 * reads: a comma-separated string or an array. Every value must be allowed;
 * an empty value passes. Used where the filters select records to act on
 * (bulk actions on everything matching), so a typo cannot widen the selection.
 */
final class FilterList implements ValidationRule
{
    /**
     * @param  list<string>|null  $allowed  Allowed values; null takes any value up to $maxLength
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

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '' || $value === []) {
            return;
        }

        $items = is_array($value) ? $value : (is_string($value) ? explode(',', $value) : null);

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
