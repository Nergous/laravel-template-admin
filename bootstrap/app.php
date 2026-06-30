<?php

use App\Http\Middleware\EnsureBotEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'bot.enabled' => EnsureBotEnabled::class,
        ]);

        // Security headers (X-Frame-Options, nosniff, HSTS, CSP with nonce) on all web responses.
        // HandleInertiaRequests — handling of Inertia requests and shared props.
        $middleware->web(append: [
            SecurityHeaders::class,
            HandleInertiaRequests::class,
        ]);

        // Which proxies to trust X-Forwarded-* headers from — taken from TRUSTED_PROXIES
        // (CIDR/IP, comma-separated). The default is EMPTY = trust no one: then
        // $request->ip()/scheme are taken from the direct connection and the client CANNOT
        // forge X-Forwarded-For. This matters: otherwise forging XFF spoofs the IP in
        // rate-limit keys (bypassing login-throttle, S4) and in audit logs. Behind a trusted
        // reverse-proxy/ingress, list its subnet: TRUSTED_PROXIES=10.0.0.0/8,...
        // The value '*' (trust everyone) is acceptable ONLY when the application is not
        // reachable directly, but only through a trusted proxy.
        $trustedProxies = (string) env('TRUSTED_PROXIES', '');
        $middleware->trustProxies(
            at: $trustedProxies === '*'
                ? '*'
                : array_values(array_filter(array_map('trim', explode(',', $trustedProxies)))),
            headers: Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO |
                Request::HEADER_X_FORWARDED_AWS_ELB
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->expectsJson() || $request->is('admin', 'admin/*')) {
                return null;
            }

            return redirect('/admin');
        });

        // Friendly Inertia error pages for the SPA: when debugging is off
        // (as in production) we serve a styled Error page instead of the default
        // Symfony screen. In local/dev (APP_DEBUG=true) we substitute nothing — a real
        // stack trace is needed. Pure JSON/API responses (search, polling) are left as
        // is: an Inertia page instead of JSON would break the client. Inertia XHR
        // (X-Inertia) is still served here — the client switches the page itself.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if (config('app.debug')) {
                return $response;
            }

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return $response;
            }

            $status = $response->getStatusCode();

            if (in_array($status, [403, 404, 419, 429, 500, 503], true)) {
                return Inertia::render('Error', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })->create();
