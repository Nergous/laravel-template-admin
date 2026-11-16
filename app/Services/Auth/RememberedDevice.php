<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Auth\Factory as AuthFactory;

/**
 * The "remember me" cookie of the current device.
 *
 * The cookie holds the user id, the remember token and an HMAC of the password
 * hash; SessionGuard accepts it only while all three match. Ending sessions
 * rotates the token and a password change the hash, so after either the current
 * device would lose "remember me" once its session expires. refresh() re-issues
 * the cookie with the current values — the same as the protected
 * SessionGuard::queueRecallerCookie().
 */
class RememberedDevice
{
    /** SessionGuard's default lifetime (minutes) when auth.guards.web.remember is not set. */
    private const DEFAULT_MINUTES = 576000;

    public function __construct(
        private readonly AuthFactory $auth,
        #[Config('auth.guards.web.remember')] private readonly ?int $minutes = null,
    ) {}

    /** Re-issues the cookie if it came with the current request; otherwise does nothing. */
    public function refresh(User $user): void
    {
        $guard = $this->auth->guard('web');

        if (! $guard instanceof SessionGuard || ! $guard->getRequest()->cookies->has($guard->getRecallerName())) {
            return;
        }

        $cookies = $guard->getCookieJar();
        $cookies->queue($cookies->make(
            $guard->getRecallerName(),
            $user->getAuthIdentifier().'|'.$user->getRememberToken().'|'.$guard->hashPasswordForCookie($user->getAuthPassword()),
            $this->minutes ?: self::DEFAULT_MINUTES,
        ));
    }
}
