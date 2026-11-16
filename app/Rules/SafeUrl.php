<?php

namespace App\Rules;

use App\Support\Site\LinkTarget;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A link the site may print or redirect to (App\Support\Site\LinkTarget).
 * An empty value passes; combine with "required" when needed.
 */
final class SafeUrl implements ValidationRule
{
    public const MESSAGE = 'Укажите путь от корня сайта (/…) или полный адрес http(s)://…';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || ! LinkTarget::isSafe($value)) {
            $fail(self::MESSAGE);
        }
    }
}
