<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
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

        if (app()->environment('production')) {
            $headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce));
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
     */
    protected function contentSecurityPolicy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self'",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
