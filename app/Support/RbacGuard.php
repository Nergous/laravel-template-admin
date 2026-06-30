<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;

/**
 * Shared privilege anti-escalation checks for RBAC.
 *
 * Principles:
 *  - a full admin bypasses the checks (holds all permissions explicitly — see RolePermissionSeeder);
 *  - "you can't grant more than you have yourself": a permission is granted only if the
 *    actor has it;
 *  - system roles (is_system: admin/operator) can only be changed/assigned by an admin.
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
     * Whether the actor can assign/change the given role: system roles —
     * admins only, custom roles — everyone (subject to the other checks).
     */
    public static function canManageRole(?User $actor, Role $role): bool
    {
        if ($actor === null) {
            return false;
        }

        return self::isAdmin($actor) || ! $role->is_system;
    }
}
