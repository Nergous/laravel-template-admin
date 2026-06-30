<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Закрывает роуты модуля бота, когда он выключен (config('bot.enabled) === false).
 * Отдаём 404, чтобы выключенный модуль был неотличим от несуществующего.
 */
class EnsureBotEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('bot.enabled'), 404);

        return $next($request);
    }
}
