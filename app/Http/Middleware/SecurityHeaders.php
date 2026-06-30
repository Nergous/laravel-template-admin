<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Добавляет HTTP security-заголовки ко всем web-ответам.
 *
 * - Статические заголовки (X-Frame-Options, nosniff, Referrer-Policy,
 *   X-XSS-Protection, Permissions-Policy) ставятся всегда.
 * - HSTS — только при HTTPS-запросе.
 * - Content-Security-Policy с per-request nonce — только в production:
 *   локальный Vite dev-server/HMR отдаёт скрипты со стороннего origin
 *   (http://localhost:5173, ws://), и строгий script-src 'self' их бы заблокировал.
 *
 * Nonce генерируется через Vite::useCspNonce() ДО рендера view, поэтому
 *
 * @vite-теги получают его автоматически, а в blade он доступен как
 * {{ \Illuminate\Support\Facades\Vite::cspNonce() }} — единственный inline-скрипт
 * (анти-флэш темы/плотности) в resources/views/admin.blade.php уже помечен им.
 */
class SecurityHeaders
{
    /** Прогоняет запрос дальше и навешивает security-заголовки (HSTS/CSP — по условиям). */
    public function handle(Request $request, Closure $next): Response
    {
        // Должно выполниться до рендера view, чтобы @vite и {{ Vite::cspNonce() }}
        // увидели один и тот же nonce.
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
     * Собирает строку CSP. script-src использует nonce — инлайн-скрипты без nonce
     * (в т.ч. инъектированные через XSS) браузер выполнять не будет.
     *
     * Единственная уступка — style-src 'unsafe-inline': nonce не покрывает
     * атрибуты style="", а XSS-риск инлайн-стилей низкий.
     *
     * @param  string  $nonce  CSP-nonce этого запроса; подставляется в script-src
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
