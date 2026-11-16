<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Name of one media library folder: no slashes and no control characters. */
final class SafeFolderName implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match('/[\/\\\\\x00-\x1F\x7F]/', $value) === 1) {
            $fail('Название папки не должно содержать слеши и управляющие символы');
        }
    }
}
