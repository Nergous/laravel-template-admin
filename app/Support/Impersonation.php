<?php

namespace App\Support;

use App\Models\User;

/**
 * The "sign in as" mode: a holder of users.impersonate temporarily works under
 * another account to see exactly what that user sees.
 *
 * The real user id lives in the server-side session under SESSION_KEY.
 * Activity log entries written in this mode keep the impersonated user as the
 * author and record the real user as impersonator_id.
 */
final class Impersonation
{
    public const SESSION_KEY = 'impersonator_id';

    public const PERMISSION = 'users.impersonate';

    /** The real user id behind the current session, or null outside the mode. */
    public static function impersonatorId(): ?int
    {
        $request = request();

        if (! $request->hasSession()) {
            return null;
        }

        $id = $request->session()->get(self::SESSION_KEY);

        return is_numeric($id) ? (int) $id : null;
    }

    public static function isActive(): bool
    {
        return self::impersonatorId() !== null;
    }

    /**
     * Only a users.impersonate holder outside the mode may start it, and only for
     * another active account that is not a superadmin and that the actor may
     * manage (no permissions above the actor's own, see RbacGuard::canManageUser).
     */
    public static function canImpersonate(?User $actor, User $target): bool
    {
        return $actor !== null
            && ! self::isActive()
            && $actor->can(self::PERMISSION)
            && ! $actor->is($target)
            && $target->is_active
            && ! $target->trashed()
            && ! RbacGuard::isAdmin($target)
            && RbacGuard::canManageUser($actor, $target);
    }
}
