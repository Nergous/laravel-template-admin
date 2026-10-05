<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Shared privilege anti-escalation checks for RBAC.
 *
 * Principles:
 *  - a full admin bypasses the checks and every ability check (Gate::before, see
 *    registerSuperadminGate()), so a route permission can never lock them out;
 *  - "you can't grant more than you have yourself": a permission is granted only if the
 *    actor has it;
 *  - system roles (is_system: admin/operator) can only be changed/assigned by an admin;
 *  - a role can only be changed by someone who holds every permission it has, so
 *    nobody can strip the role of a user above them;
 *  - the superadmin role's permission set is immutable.
 */
class RbacGuard
{
    /**
     * The superadmin role name — the single source of truth,
     * taken from config('rbac.superadmin_role'). This is the role with full access:
     * its permissions are locked in the matrix and it cannot be removed from yourself.
     * It differs from a "protected" role (is_system: cannot be deleted/renamed), of which
     * there can be several.
     */
    public static function superadminRole(): string
    {
        return config('rbac.superadmin_role', 'admin');
    }

    /** Whether the role is the superadmin (by name from config('rbac.superadmin_role')). */
    public static function isSuperadminRole(Role $role): bool
    {
        return $role->name === self::superadminRole();
    }

    /** A full administrator bypasses the anti-escalation checks. */
    public static function isAdmin(?User $actor): bool
    {
        return $actor?->hasRole(self::superadminRole()) === true;
    }

    /**
     * Grants the superadmin every ability before any permission or policy check
     * runs. Route "permission:" middleware calls $user->canAny(), so it honours
     * this too: removing a permission row can never lock the superadmin out.
     */
    public static function registerSuperadminGate(): void
    {
        Gate::before(fn ($user) => $user instanceof User && self::isAdmin($user) ? true : null);
    }

    /**
     * Whether the actor can grant the given permission: only if they hold it
     * themselves (or they're an admin). Protects against granting permissions above
     * one's own level.
     */
    public static function canGrantPermission(?User $actor, ?string $permission): bool
    {
        if ($actor === null || $permission === null) {
            return false;
        }

        return self::isAdmin($actor) || $actor->can($permission);
    }

    /**
     * Whether the actor can assign/change the given role: system roles — admins
     * only; other roles — only if the actor holds every permission the role has
     * (otherwise a roles.edit/permissions.edit holder could strip the role of a
     * user above them and then take that account over).
     */
    public static function canManageRole(?User $actor, Role $role): bool
    {
        if ($actor === null) {
            return false;
        }

        if (self::isAdmin($actor)) {
            return true;
        }

        return ! $role->is_system && self::missingPermissions($actor, $role)->isEmpty();
    }

    /**
     * Permissions of the role that the actor does not hold.
     *
     * @return Collection<int, string>
     */
    public static function missingPermissions(User $actor, Role $role): Collection
    {
        if (self::isAdmin($actor)) {
            return collect();
        }

        $held = self::permissionNames($actor);

        return $role->permissions
            ->pluck('name')
            ->reject(fn (string $permission) => $held->has($permission))
            ->values();
    }

    /**
     * Whether the actor can edit, block, delete, or restore the target user.
     *
     * An admin manages everyone; anyone manages their own account (the role
     * rules still apply to it). Otherwise the target must hold no system role
     * and no permission the actor lacks — so a users.edit holder cannot take
     * over an administrator by changing their email or password.
     */
    public static function canManageUser(?User $actor, User $target): bool
    {
        if ($actor === null) {
            return false;
        }

        $actor->loadMissing(['roles.permissions', 'permissions']);

        if (self::isAdmin($actor) || $actor->is($target)) {
            return true;
        }

        $target->loadMissing(['roles.permissions', 'permissions']);

        if ($target->roles->contains('is_system', true)) {
            return false;
        }

        $actorPermissions = self::permissionNames($actor);

        return $target->getAllPermissions()
            ->pluck('name')
            ->every(fn (string $permission) => $actorPermissions->has($permission));
    }

    /**
     * The actor's permission names (direct and via roles) as a lookup map.
     *
     * @return Collection<string, int>
     */
    private static function permissionNames(User $actor): Collection
    {
        $actor->loadMissing(['roles.permissions', 'permissions']);

        return $actor->getAllPermissions()->pluck('name')->flip();
    }
}
