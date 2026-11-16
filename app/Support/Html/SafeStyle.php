<?php

namespace App\Support\Html;

/**
 * Checks the style attribute of sanitized HTML: only layout properties
 * (alignment, floats, sizes) with simple values remain — no url(), expressions
 * or quotes.
 */
final class SafeStyle
{
    private const PROPERTIES = ['text-align', 'float', 'width', 'height', 'vertical-align'];

    public static function filter(string $style): string
    {
        $kept = [];

        foreach (explode(';', $style) as $declaration) {
            [$property, $value] = array_pad(array_map('trim', explode(':', $declaration, 2)), 2, '');
            $property = strtolower($property);

            if (! in_array($property, self::PROPERTIES, true)) {
                continue;
            }

            if (preg_match('/^[a-z0-9.%\- ]{1,40}$/i', $value) !== 1) {
                continue;
            }

            $kept[] = $property.': '.$value;
        }

        return implode('; ', $kept);
    }
}
