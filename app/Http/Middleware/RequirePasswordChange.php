<?php

namespace App\Http\Middleware;

use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a user with users.must_change_password on the profile page until they
 * set a new password. Only the profile routes and logout stay reachable.
 * An admin working "as" such a user is not held there: the password is
 * the user's own business.
 */
class RequirePasswordChange
{
    /** Route names available while the password change is pending. */
    private const ALLOWED_ROUTES = ['admin.profile.*', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null
            || ! $user->must_change_password
            || $request->routeIs(...self::ALLOWED_ROUTES)
            || Impersonation::isActive()) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            abort(403, 'Сначала смените пароль');
        }

        return redirect()
            ->route('admin.profile.show')
            ->with('warning', 'Сначала смените пароль');
    }
}
