<?php

namespace App\Services\Access;

use App\Models\Role;
use App\Models\User;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;
use WeakMap;

/**
 * Shared RBAC checks against privilege escalation.
 *
 * Principles:
 *  - the full administrator passes every check and permission (Gate::before
 *    in AppServiceProvider), so a route permission cannot lock them out;
 *  - "you cannot grant more than you have": a permission is granted only if
 *    the actor holds it;
 *  - system roles (is_system: admin/operator) are changed and assigned only by the administrator;
 *  - a role or user can be changed only by someone holding all of their
 *    permissions, so nobody strips a role from a user above themselves;
 *  - the superadmin role's permission set never changes.
 *
 * Rules about a specific user or role live in App\Policies\UserPolicy and
 * RolePolicy; this class holds the superadmin role, the Gate bypass and the
 * shared permission checks the policies build on.
 *
 * Scoped: lives for one request, so the actor's permissions are collected
 * once rather than for every list row.
 */
#[Scoped]
class RbacGuard
{
    /** @var WeakMap<User, array{roles: mixed, permissions: mixed, names: Collection<string, int>}> */
    private WeakMap $permissionNames;

    public function __construct(
        private readonly PermissionRegistrar $registrar,
    ) {
        $this->permissionNames = new WeakMap;
    }

    /**
     * Name of the superadmin role — the single source, read from
     * config('rbac.superadmin_role'). This is the full-access role: its
     * permissions are locked in the matrix and it cannot be removed from
     * oneself. Unlike a "protected" role (is_system: cannot be deleted or
     * renamed), of which there may be several.
     */
    public function superadminRole(): string
    {
        return config('rbac.superadmin_role', 'admin');
    }

    /** Whether the role is the superadmin role (by the name from config('rbac.superadmin_role')). */
    public function isSuperadminRole(Role $role): bool
    {
        return $role->name === $this->superadminRole();
    }

    /** The superadmin skips the privilege-escalation checks. */
    public function isSuperadmin(?User $actor): bool
    {
        return $actor?->hasRole($this->superadminRole()) === true;
    }

    /**
     * Whether the actor may grant a permission: only if they hold it.
     * Prevents granting permissions above one's own level. Gate::before lets
     * the superadmin through, so no separate check is needed for them.
     */
    public function canGrantPermission(User $actor, string $permission): bool
    {
        return $actor->can($permission);
    }

    /**
     * Role permissions the actor does not hold.
     *
     * @return Collection<int, string>
     */
    public function missingPermissions(User $actor, Role $role): Collection
    {
        if ($this->isSuperadmin($actor)) {
            return collect();
        }

        return $this->uncovered($actor, $role->permissions->pluck('name'));
    }

    /** Whether the actor holds every permission of another user (direct and via roles). */
    public function coversUser(User $actor, User $target): bool
    {
        if ($this->isSuperadmin($actor)) {
            return true;
        }

        return $this->uncovered($actor, $this->permissionNames($target)->keys())->isEmpty();
    }

    /**
     * Names of all the user's effective permissions — for conditional
     * rendering in the admin panel. The superadmin passes every check
     * (Gate::before), so they get every permission name.
     *
     * @return list<string>
     */
    public function effectivePermissions(User $user): array
    {
        $permissions = $this->isSuperadmin($user)
            ? $this->registrar->getPermissions(['guard_name' => 'web'])
            : $user->getAllPermissions();

        return $permissions->pluck('name')->values()->all();
    }

    /**
     * Permissions from the list the actor does not hold.
     *
     * @param  Collection<int, string>  $permissions
     * @return Collection<int, string>
     */
    private function uncovered(User $actor, Collection $permissions): Collection
    {
        $held = $this->permissionNames($actor);

        return $permissions
            ->reject(fn (string $permission) => $held->has($permission))
            ->values();
    }

    /**
     * The user's permissions (direct and via roles) as a lookup map.
     * Memoized per user object while it keeps the same loaded relations:
     * after refresh() or reloading the roles the list is rebuilt.
     *
     * @return Collection<string, int>
     */
    private function permissionNames(User $user): Collection
    {
        $user->loadMissing(['roles.permissions', 'permissions']);
        $roles = $user->getRelation('roles');
        $permissions = $user->getRelation('permissions');
        $cached = $this->permissionNames[$user] ?? null;

        if ($cached !== null && $cached['roles'] === $roles && $cached['permissions'] === $permissions) {
            return $cached['names'];
        }

        $names = $user->getAllPermissions()->pluck('name')->flip();
        $this->permissionNames[$user] = ['roles' => $roles, 'permissions' => $permissions, 'names' => $names];

        return $names;
    }
}
