<?php

namespace App\Services\System;

use App\Services\Media\MediaStorage;
use InvalidArgumentException;
use RuntimeException;

/**
 * Content-Security-Policy header of the site and the admin panel.
 *
 * script-src uses the request nonce: without it the browser will not execute
 * inline scripts (including ones injected via XSS). Concessions: style-src
 * 'unsafe-inline' (the nonce does not cover style="" attributes, and the XSS
 * risk through inline styles is low) and img-src https: (rich-text HTML may hold
 * external images; App\Support\Html\HtmlSanitizer keeps only http(s) image
 * URLs and upgrades http to https).
 *
 * External hosts are allowed only when the settings or the media disk need
 * them: absolute image URLs from the settings and a media disk on another host.
 */
class ContentSecurityPolicy
{
    public function __construct(
        private readonly MediaStorage $media,
        private readonly Settings $settings,
    ) {}

    /**
     * Builds the header value.
     *
     * @param  string  $nonce  CSP nonce of this request; substituted into script-src
     * @param  string  $appOrigin  scheme://host[:port] of the request; a media disk on the same origin adds nothing
     */
    public function header(string $nonce, string $appOrigin): string
    {
        $mediaOrigins = $this->mediaDiskOrigins($appOrigin);
        $imageOrigins = array_values(array_unique([...$this->settingsImageOrigins(), ...$mediaOrigins]));

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            trim("img-src 'self' data: https: ".implode(' ', $imageOrigins)),
            trim("media-src 'self' ".implode(' ', $mediaOrigins)),
            // Files (e.g. PDF) may be shown in a frame and live on the media disk host.
            trim("frame-src 'self' ".implode(' ', $mediaOrigins)),
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    /**
     * Origins of absolute image URLs from the settings (favicon, OG image). The
     * settings form accepts http(s) links, so the policy must allow exactly
     * these hosts — otherwise the favicon and OG previews break in production.
     * Before migrations Settings returns the defaults, so a database error
     * need not be caught here.
     *
     * @return list<string>
     */
    private function settingsImageOrigins(): array
    {
        $urls = [
            $this->settings->value('general', 'favicon'),
            $this->settings->value('seo', 'og_image'),
        ];

        $origins = [];
        foreach ($urls as $url) {
            $origin = $this->originOf($url);
            if ($origin !== null) {
                $origins[$origin] = $origin;
            }
        }

        return array_values($origins);
    }

    /**
     * Origin of the media disk (config('media.disk')) when another host serves
     * its files — an S3 bucket or a CDN. The default public disk lives on the
     * application's own origin and adds nothing. An unconfigured disk or a
     * driver without public URLs adds nothing either.
     *
     * @return list<string>
     */
    private function mediaDiskOrigins(string $appOrigin): array
    {
        try {
            $origin = $this->originOf($this->media->disk()->url('media'));
        } catch (InvalidArgumentException|RuntimeException) {
            return [];
        }

        return $origin === null || $origin === strtolower($appOrigin) ? [] : [$origin];
    }

    /** scheme://host[:port] of an absolute http(s) URL, null for anything else. */
    private function originOf(mixed $url): ?string
    {
        $parts = is_string($url) ? parse_url($url) : false;

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }

        return strtolower($parts['scheme'].'://'.$parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
