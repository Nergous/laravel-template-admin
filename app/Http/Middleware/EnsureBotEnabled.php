<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes the bot module routes when it is disabled (config('bot.enabled) === false).
 * We return 404 so a disabled module is indistinguishable from a nonexistent one.
 */
class EnsureBotEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('bot.enabled'), 404);

        return $next($request);
    }
}
