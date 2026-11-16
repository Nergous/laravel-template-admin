<?php

namespace App\Support\Site;

/**
 * A link the site may print or redirect to: a root-relative path ("/…", but not
 * "//host" or "/\host" — browsers treat those as another host) or an absolute
 * http(s) URL. Other schemes (javascript:, data:, …), spaces, control
 * characters and backslashes are rejected.
 */
final class LinkTarget
{
    public static function isSafe(string $url): bool
    {
        // preg_match() returns false on malformed UTF-8 — that is rejected too.
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
}
