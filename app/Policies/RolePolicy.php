<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Services\Access\RbacGuard;

/**
 * Record-level rules for roles: whether the actor may change or delete this
 * role. Whether they may edit roles at all (roles.edit, roles.delete,
 * permissions.edit) is decided by the "permission:" route middleware; the role
 * routes apply this policy via "can:update,role" / "can:delete,role".
 *
 * The superadmin passes before this policy (Gate::before in
 * AppServiceProvider). For everyone else system roles are closed, and a role
 * may be changed only by someone holding all its permissions: otherwise a
 * roles.edit or permissions.edit holder could strip a role from a user above
 * them and then take over their account.
 */
class RolePolicy
{
    public function __construct(private readonly RbacGuard $rbac) {}

    public function update(User $actor, Role $role): bool
    {
        return ! $role->is_system && $this->rbac->missingPermissions($actor, $role)->isEmpty();
    }

    /** The same rule as update(): a role above the actor is off-limits either way. */
    public function delete(User $actor, Role $role): bool
    {
        return $this->update($actor, $role);
    }
}
