<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A link the site may render or redirect to: a root-relative path ("/…",
 * but not "//host" or "/\host", which browsers treat as another host) or an
 * absolute http(s) URL. Other schemes (javascript:, data:, …), whitespace,
 * control characters and backslashes are rejected. Empty values pass; combine
 * with "required" when needed.
 */
final class SafeUrl implements ValidationRule
{
    public const MESSAGE = 'Укажите путь от корня сайта (/…) или полный адрес http(s)://…';

    public function __construct(private readonly string $message = self::MESSAGE) {}

    public static function isSafe(string $url): bool
    {
        // preg_match() returns false on invalid UTF-8, which is rejected as well.
        if ($url === '' || preg_match('/[\s\p{Cc}\\\\]/u', $url) !== 0) {
            return false;
        }

        if (preg_match('#^/(?!/)#', $url)) {
            return true;
        }

        if (! preg_match('#^https?://[^/?\#]#i', $url)) {
            return false;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && preg_match('/^[\p{L}\p{N}](?:[\p{L}\p{N}.-]*[\p{L}\p{N}])?$/u', $host) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || ! self::isSafe($value)) {
            $fail($this->message);
        }
    }
}
