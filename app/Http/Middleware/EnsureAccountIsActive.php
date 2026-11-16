<?php

namespace App\Http\Middleware;

use App\Services\Auth\AdminLogin;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of a user who was blocked while signed in.
 *
 * Sign-in already rejects blocked accounts; this closes the gap for sessions
 * opened before the block (including "remember me" cookies).
 */
class EnsureAccountIsActive
{
    public function __construct(private readonly AdminLogin $login) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->is_active) {
            return $next($request);
        }

        $this->login->endSession($request->session());

        if ($request->expectsJson() && ! $request->inertia()) {
            abort(401);
        }

        $message = $user->blocked_reason
            ? __('auth.blocked_with_reason', ['reason' => $user->blocked_reason])
            : __('auth.blocked');

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
