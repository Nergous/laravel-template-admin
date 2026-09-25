<?php

namespace App\Http\Middleware;

use App\Models\Media;
use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds HTTP security headers to all web responses.
 *
 * - Static headers (X-Frame-Options, nosniff, Referrer-Policy,
 *   X-XSS-Protection, Permissions-Policy) are always set.
 * - HSTS — only on an HTTPS request.
 * - Content-Security-Policy with a per-request nonce — only in production:
 *   the local Vite dev-server/HMR serves scripts from a third-party origin
 *   (http://localhost:5173, ws://), and a strict script-src 'self' would block them.
 *
 * The nonce is generated via Vite::useCspNonce() BEFORE the view is rendered, so
 *
 * @vite tags receive it automatically, and in blade it is available as
 * {{ \Illuminate\Support\Facades\Vite::cspNonce() }} — the single inline script
 * (theme/density anti-flash) in resources/views/admin.blade.php is already marked with it.
 */
class SecurityHeaders
{
    /** Passes the request through and attaches security headers (HSTS/CSP — conditionally). */
    public function handle(Request $request, Closure $next): Response
    {
        // Must run before the view is rendered so that @vite and {{ Vite::cspNonce() }}
        // see the same nonce.
        $nonce = Vite::useCspNonce();

        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('X-XSS-Protection', '0');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        if ($request->secure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // The admin panel never belongs in search results, whatever seo.indexable says.
        if ($request->is('admin', 'admin/*')) {
            $headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        if (app()->environment('production')) {
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce, $this->mediaDiskOrigins($request)));
        }

        return $response;
    }

    /**
     * Builds the CSP string. script-src uses the nonce — the browser will not execute
     * inline scripts without a nonce (including those injected via XSS).
     *
     * The only concession is style-src 'unsafe-inline': the nonce does not cover
     * style="" attributes, and the XSS risk of inline styles is low.
     *
     * @param  string  $nonce  the CSP nonce of this request; substituted into script-src
     * @param  list<string>  $mediaOrigins  external origin of the media disk (see mediaDiskOrigins())
     */
    protected function contentSecurityPolicy(string $nonce, array $mediaOrigins = []): string
    {
        $imageOrigins = array_values(array_unique([...$this->settingsImageOrigins(), ...$mediaOrigins]));

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            trim("img-src 'self' data: ".implode(' ', $imageOrigins)),
            trim("media-src 'self' ".implode(' ', $mediaOrigins)),
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    /**
     * Origins of absolute image URLs chosen in the settings (favicon, OG image).
     * The settings form accepts http(s) links, so the policy must allow exactly
     * those hosts — otherwise the favicon and the OG preview break in production.
     *
     * @return list<string>
     */
    protected function settingsImageOrigins(): array
    {
        try {
            $urls = [Setting::value('general', 'favicon'), Setting::value('seo', 'og_image')];
        } catch (\Throwable) {
            return [];
        }

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
     * Origin of the media disk (config('media.disk')) when its files are served
     * from another host — an S3 bucket or a CDN. The default public disk lives
     * on the app's own origin and adds nothing.
     *
     * @return list<string>
     */
    protected function mediaDiskOrigins(Request $request): array
    {
        try {
            $origin = $this->originOf(Storage::disk(Media::diskName())->url('media'));
        } catch (\Throwable) {
            return [];
        }

        return $origin === null || $origin === strtolower($request->getSchemeAndHttpHost()) ? [] : [$origin];
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
