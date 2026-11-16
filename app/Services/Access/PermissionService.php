<?php

namespace App\Services\Access;

use App\Events\ActivityOccurred;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\PermissionDiff;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operations on the "role × permission" matrix with privilege-escalation
 * protection and logging. Permissions themselves are defined in code
 * (RolePermissionSeeder and migrations) and are not editable from the panel.
 *
 * A role may be changed if it is not the superadmin role and RolePolicy::update()
 * allows it for the actor; a permission may be granted only by someone who holds
 * it (RbacGuard::canGrantPermission()). Changes are logged in the same format as
 * from the role form (PermissionDiff).
 */
class PermissionService
{
    public function __construct(
        private readonly RbacGuard $rbac,
        private readonly Gate $gate,
    ) {}

    /**
     * Toggles one matrix cell: grants or revokes a permission for a role. If the
     * role is already in the requested state, nothing is written or logged.
     *
     * @param  string  $permission  Permission name (module.action)
     * @param  bool  $granted  true — grant to the role, false — revoke
     *
     * @throws ValidationException If the role is locked or privilege-escalation protection is violated.
     */
    public function toggle(Role $role, string $permission, bool $granted, User $actor): void
    {
        $this->ensureRoleEditable($role, $actor);

        if ($granted && ! $this->rbac->canGrantPermission($actor, $permission)) {
            throw ValidationException::withMessages([
                'matrix' => 'Нельзя выдать право, которого у вас нет',
            ]);
        }

        DB::transaction(fn () => $this->apply($role, [$permission], $granted, $actor));
    }

    /**
     * Grants or revokes several permissions for several roles at once (a whole
     * matrix row or a group column). The rules are those of toggle(); roles and
     * permissions the actor cannot change are skipped. One log entry per
     * changed role.
     *
     * @param  list<int>  $roleIds
     * @param  array<int, string>  $permissions
     * @return int How many roles changed
     *
     * @throws ValidationException If nothing is left that the actor may change.
     */
    public function toggleMany(array $roleIds, array $permissions, bool $granted, User $actor): int
    {
        $grantable = $granted
            ? array_values(array_filter($permissions, fn (string $p) => $this->rbac->canGrantPermission($actor, $p)))
            : array_values($permissions);

        $roles = Role::query()
            ->whereKey($roleIds)
            ->with('permissions:id,name')
            ->get()
            ->filter(fn (Role $role) => $this->canEdit($role, $actor));

        if ($roles->isEmpty() || $grantable === []) {
            throw ValidationException::withMessages([
                'matrix' => 'Нет ролей или прав, которые вы можете изменить',
            ]);
        }

        return DB::transaction(fn () => $roles
            ->filter(fn (Role $role) => $this->apply($role, $grantable, $granted, $actor))
            ->count());
    }

    /**
     * Grants or revokes permissions for a role and writes one log entry.
     * Permissions already in the requested state are skipped.
     *
     * @param  array<int, string>  $permissions
     * @return bool Whether the role changed
     */
    private function apply(Role $role, array $permissions, bool $granted, User $actor): bool
    {
        $current = $role->permissions->pluck('name')->all();
        $delta = $granted
            ? array_values(array_diff($permissions, $current))
            : array_values(array_intersect($permissions, $current));

        if ($delta === []) {
            return false;
        }

        $granted ? $role->givePermissionTo($delta) : $role->revokePermissionTo($delta);

        ActivityOccurred::by($actor, $role, 'updated', $granted
            ? PermissionDiff::granted($delta)
            : PermissionDiff::revoked($delta));

        return true;
    }

    private function canEdit(Role $role, User $actor): bool
    {
        return ! $this->rbac->isSuperadminRole($role)
            && $this->gate->forUser($actor)->allows('update', $role);
    }

    /**
     * @throws ValidationException If this is the superadmin role or the actor cannot manage it.
     */
    private function ensureRoleEditable(Role $role, User $actor): void
    {
        if ($this->canEdit($role, $actor)) {
            return;
        }

        throw ValidationException::withMessages([
            'matrix' => match (true) {
                $this->rbac->isSuperadminRole($role) => 'Права роли «'.$role->name.'» нельзя изменять',
                (bool) $role->is_system => 'Права системной роли может менять только администратор',
                default => 'У роли «'.$role->name.'» есть права, которых нет у вас: '
                    .$this->rbac->missingPermissions($actor, $role)->implode(', '),
            },
        ]);
    }
}
