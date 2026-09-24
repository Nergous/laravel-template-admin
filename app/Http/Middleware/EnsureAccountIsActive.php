<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of a user who was blocked while signed in.
 *
 * Login already rejects blocked accounts; this closes the gap for sessions
 * opened before the block (including "remember me" cookies).
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                abort(401);
            }

            return redirect()
                ->route('login')
                ->withErrors(['email' => 'Учётная запись заблокирована']);
        }

        return $next($request);
    }
}
