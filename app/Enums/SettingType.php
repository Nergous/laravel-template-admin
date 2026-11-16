<?php

namespace App\Enums;

/**
 * Setting value type. The settings table stores the value as a string; the type
 * defines the cast on read, the string written and the default validation rules.
 */
enum SettingType: string
{
    case String = 'string';
    case Text = 'text';
    case Html = 'html';
    case Bool = 'bool';
    case Int = 'int';

    /**
     * Casts a value from the database or a default value to the field type.
     *
     * @param  mixed  $raw  The string value from the database or the default value
     * @return mixed bool for Bool, int for Int, string for the other types
     */
    public function cast(mixed $raw): mixed
    {
        return match ($this) {
            self::Bool => (bool) (int) $raw,
            self::Int => (int) $raw,
            self::String, self::Text, self::Html => (string) $raw,
        };
    }

    /**
     * Turns a field value into the string written to the settings table.
     *
     * @param  mixed  $value  The validated field value
     * @return string '1' or '0' for Bool, the number as a string for Int, text for the other types
     */
    public function serialize(mixed $value): string
    {
        return match ($this) {
            self::Bool => $value ? '1' : '0',
            self::Int => (string) (int) $value,
            self::String, self::Text, self::Html => (string) ($value ?? ''),
        };
    }

    /**
     * Default validation rules of a field of this type. Specific fields get
     * their own rules in App\Support\Settings\SettingsRules.
     *
     * @return list<mixed>
     */
    public function rules(): array
    {
        return match ($this) {
            self::Bool => ['required', 'boolean'],
            self::Int => ['required', 'integer', 'min:1', 'max:100000'],
            self::Text => ['nullable', 'string', 'max:1000'],
            self::Html => ['nullable', 'string', 'max:20000'],
            self::String => ['nullable', 'string', 'max:255'],
        };
    }
}
