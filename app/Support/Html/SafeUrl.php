<?php

namespace App\Support\Html;

/**
 * Checks href and src URLs of sanitized HTML. Relative URLs and allowed schemes
 * pass; anything else (javascript:, data: etc.) becomes an empty string — such
 * an attribute is dropped.
 */
final class SafeUrl
{
    private const LINK_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    private const IMAGE_SCHEMES = ['http', 'https'];

    /** Link URL: http(s), mailto, tel or relative. */
    public static function link(string $url): string
    {
        return self::filter($url, self::LINK_SCHEMES);
    }

    /**
     * Image URL: only http(s) or relative, and http is upgraded to https. The
     * site runs over https, where the browser blocks or upgrades mixed-content
     * images anyway, and the production CSP allows https: images.
     */
    public static function image(string $url): string
    {
        $url = self::filter($url, self::IMAGE_SCHEMES);

        return self::scheme($url) === 'http' ? 'https:'.substr($url, 5) : $url;
    }

    /** @param  list<string>  $schemes */
    private static function filter(string $url, array $schemes): string
    {
        if ($url === '' || preg_match('/[\x00-\x1f\x7f\\\\]/', $url) === 1) {
            return '';
        }

        if (str_starts_with($url, '//')) {
            return 'https:'.$url;
        }

        if (str_starts_with($url, '/') || str_starts_with($url, '#') || str_starts_with($url, '?')) {
            return $url;
        }

        $scheme = self::scheme($url);

        if ($scheme === '') {
            // A relative path like "files/x.pdf" stays unless a scheme hides in it.
            return str_contains(strtok($url, '/?#') ?: '', ':') ? '' : $url;
        }

        return in_array($scheme, $schemes, true) ? $url : '';
    }

    private static function scheme(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_SCHEME));
    }
}
