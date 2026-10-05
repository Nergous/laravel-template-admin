<?php

use App\Http\Middleware\SecurityHeaders;
use App\Support\RbacGuard;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
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
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // Security headers (X-Frame-Options, nosniff, HSTS, CSP with nonce) on all web responses.
        // HandleInertiaRequests is attached to the /admin route group only (routes/web.php):
        // a public site added later needs none of the admin shared props.
        $middleware->web(append: [
            SecurityHeaders::class,
        ]);

        // The guest-only login page sends a signed-in user to the admin panel.
        // Laravel's default target is "/" (no "dashboard"/"home" route exists):
        // when the idle timer sends an Inertia visit to /admin/login while the
        // session is still alive, it would otherwise bounce through the "/" redirect.
        $middleware->redirectUsersTo(fn () => route('admin.dashboard'));

        // Which proxies to trust X-Forwarded-* headers from: config/trustedproxy.php
        // (TRUSTED_PROXIES). No "at:" here on purpose — the TrustProxies middleware
        // then reads config('trustedproxy.proxies') per request, which survives
        // config:cache (this callback runs before the configuration is loaded).
        $middleware->trustProxies(
            headers: Request::HEADER_X_FORWARDED_FOR |
                Request::HEADER_X_FORWARDED_HOST |
                Request::HEADER_X_FORWARDED_PORT |
                Request::HEADER_X_FORWARDED_PROTO |
                Request::HEADER_X_FORWARDED_AWS_ELB
        );
    })
    // The superadmin passes every ability check, so no permission row (or a
    // route's permission: middleware) can lock them out of the panel.
    ->booted(fn () => RbacGuard::registerSuperadminGate())
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
            // An expired CSRF token (the page sat open past the session lifetime):
            // return to the page with a hint; the reload brings a fresh token.
            if ($response->getStatusCode() === 419 && ! ($request->expectsJson() && ! $request->header('X-Inertia'))) {
                return back()->with('warning', 'Страница устарела, повторите действие');
            }

            if (config('app.debug')) {
                return $response;
            }

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return $response;
            }

            $status = $response->getStatusCode();

            if (in_array($status, [403, 404, 419, 429, 500, 503], true)) {
                // Maintenance mode (503) stops the request before the route
                // middleware, so HandleInertiaRequests never sets the root view.
                Inertia::setRootView('admin');

                return Inertia::render('Error', ['status' => $status])
                    ->toResponse($request)
                    ->setStatusCode($status);
            }

            return $response;
        });
    })->create();
