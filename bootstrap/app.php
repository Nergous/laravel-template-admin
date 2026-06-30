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

        // Security-заголовки (X-Frame-Options, nosniff, HSTS, CSP с nonce) на все web-ответы.
        // HandleInertiaRequests — обработка Inertia-запросов и shared props.
        $middleware->web(append: [
            SecurityHeaders::class,
            HandleInertiaRequests::class,
        ]);

        // Каким прокси доверять заголовки X-Forwarded-* — берём из TRUSTED_PROXIES
        // (CIDR/IP через запятую). Дефолт — ПУСТО = не доверять никому: тогда
        // $request->ip()/схема берутся из прямого подключения и клиент НЕ может
        // подделать X-Forwarded-For. Это важно: иначе подделкой XFF подменяется IP в
        // ключах rate-limit (обход login-throttle, S4) и в аудит-логах. За доверенным
        // reverse-proxy/ingress пропишите его подсеть: TRUSTED_PROXIES=10.0.0.0/8,...
        // Значение '*' (доверять всем) допустимо ТОЛЬКО когда приложение недоступно
        // напрямую, а лишь через доверенный прокси.
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

        // Дружелюбные Inertia-страницы ошибок для SPA: при выключенной отладке
        // (как в production) отдаём стилизованную страницу Error вместо дефолтного
        // Symfony-экрана. В local/dev (APP_DEBUG=true) ничего не подменяем — нужен
        // реальный стектрейс. Чистые JSON/API-ответы (поиск, поллинг) оставляем как
        // есть: Inertia-страница вместо JSON сломала бы клиента. Inertia-XHR
        // (X-Inertia) при этом обслуживаем — клиент сам переключит страницу.
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
