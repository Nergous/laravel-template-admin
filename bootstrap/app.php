<?php

use App\Exceptions\ErrorPageResponder;
use App\Http\Middleware\ApplyRuntimeSettings;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission' => PermissionMiddleware::class,
        ]);

        // Settings from the database (app name, session lifetime) apply before StartSession.
        $middleware->web(prepend: [
            ApplyRuntimeSettings::class,
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
                Request::HEADER_X_FORWARDED_PROTO
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Error pages (and the 404 redirect of unknown non-admin addresses to
        // /admin) come from ErrorPageResponder, built by the container.
        $exceptions->respond(fn (Response $response, Throwable $e, Request $request) => app(ErrorPageResponder::class)($response, $e, $request));
    })->create();
