<?php

namespace App\Http\Middleware;

use App\Services\System\ContentSecurityPolicy;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds security HTTP headers to all web responses.
 *
 * - Static headers (X-Frame-Options, nosniff, Referrer-Policy,
 *   X-XSS-Protection, Permissions-Policy) are always set.
 * - HSTS — only on HTTPS requests.
 * - Content-Security-Policy with a per-request nonce — production only: the
 *   local Vite/HMR dev server serves scripts from another origin
 *   (http://localhost:5173, ws://), and a strict script-src 'self' would block them.
 *   The policy itself is built by App\Services\System\ContentSecurityPolicy.
 *
 * The nonce is generated via Vite::useCspNonce() BEFORE the view renders, so the
 * Vite tags get it automatically, and in Blade it is available as
 * {{ \Illuminate\Support\Facades\Vite::cspNonce() }} — the only inline script
 * (theme flicker guard) in resources/views/admin.blade.php already carries it.
 */
class SecurityHeaders
{
    private const STATIC_HEADERS = [
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'X-XSS-Protection' => '0',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
    ];

    public function __construct(
        private readonly Application $app,
        private readonly Vite $vite,
        private readonly ContentSecurityPolicy $csp,
    ) {}

    /** Passes the request on and adds the security headers (HSTS/CSP conditionally). */
    public function handle(Request $request, Closure $next): Response
    {
        // Must run before the view renders so that @vite and {{ Vite::cspNonce() }}
        // see the same nonce.
        $nonce = $this->vite->useCspNonce();

        $response = $next($request);
        $headers = $response->headers;

        $headers->add(self::STATIC_HEADERS);

        if ($request->secure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // The admin panel does not belong in search results, whatever seo.indexable says.
        if ($request->is('admin', 'admin/*')) {
            $headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        if ($this->app->environment('production')) {
            $headers->set('Content-Security-Policy', $this->csp->header($nonce, $request->getSchemeAndHttpHost()));
        }

        return $response;
    }
}
