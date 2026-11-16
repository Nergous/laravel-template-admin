<?php

namespace App\Enums\Concerns;

/**
 * Russian labels of string enum cases from lang/ru/enums.php.
 * Translation key: enums.<EnumName>.<CaseName>.
 */
trait HasLabel
{
    public function label(): string
    {
        return trans('enums.'.class_basename(static::class).'.'.$this->name);
    }

    /**
     * Options for admin dropdowns: [{value, label}] over all cases.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case) => ['value' => (string) $case->value, 'label' => $case->label()],
            static::cases(),
        );
    }

    /**
     * All enum values — for validation rules such as FilterList::of().
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case) => (string) $case->value, static::cases());
    }
}
