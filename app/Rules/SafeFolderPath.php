<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Media library folder path ("Banners/2026"): no backslashes or control
 * characters, no "." or ".." parts.
 */
final class SafeFolderPath implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (preg_match('/[\\\\\x00-\x1F\x7F]/', $value) === 1 || preg_match('/(^|\/)\.{1,2}(\/|$)/', $value) === 1) {
            $fail('Путь к папке не должен содержать «\\», управляющие символы и части «.» или «..»');
        }
    }
}
