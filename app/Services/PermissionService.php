<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Support\RbacGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Domain operations on the "role x permission" matrix, with anti-escalation and
 * logging. Permissions themselves are defined in code (seeders and migrations,
 * all marked is_system) and are not edited from the panel.
 */
class PermissionService
{
    /**
     * Toggles a single matrix cell: grant/revoke a permission for a role.
     *
     * @param  int  $roleId  Role identifier
     * @param  string  $permission  Permission name (module.action)
     * @param  bool  $granted  true — grant the permission to the role, false — revoke it
     *
     * @throws ValidationException If the role is locked or anti-escalation is violated.
     */
    public function toggle(int $roleId, string $permission, bool $granted, ?User $actor): void
    {
        /** @var Role $role */
        $role = Role::findOrFail($roleId);

        $this->ensureRoleEditable($role, $actor);

        if ($granted && ! RbacGuard::canGrantPermission($actor, $permission)) {
            throw ValidationException::withMessages([
                'matrix' => 'Нельзя выдать право, которого у вас нет',
            ]);
        }

        DB::transaction(function () use ($role, $permission, $granted) {
            if ($granted) {
                $role->givePermissionTo($permission);
            } else {
                $role->revokePermissionTo($permission);
            }

            ActivityLog::record($role, 'updated', [
                'permission' => [$permission, $granted ? 'выдано' : 'снято'],
            ]);
        });
    }

    /**
     * Grants or revokes several permissions for several roles at once (a whole
     * matrix row or a group column). The same rules as toggle() apply; roles or
     * permissions the actor may not change are skipped. One log entry per
     * changed role.
     *
     * @param  array<int, int>  $roleIds
     * @param  array<int, string>  $permissions
     * @return int How many roles were changed
     */
    public function toggleMany(array $roleIds, array $permissions, bool $granted, ?User $actor): int
    {
        $grantable = $granted
            ? array_values(array_filter($permissions, fn (string $p) => RbacGuard::canGrantPermission($actor, $p)))
            : array_values($permissions);

        $roles = Role::whereIn('id', $roleIds)->with('permissions:id,name')->get()
            ->reject(fn (Role $role) => RbacGuard::isSuperadminRole($role) || ! RbacGuard::canManageRole($actor, $role));

        if ($roles->isEmpty() || $grantable === []) {
            throw ValidationException::withMessages([
                'matrix' => 'Нет ролей или прав, которые вы можете изменить',
            ]);
        }

        return DB::transaction(function () use ($roles, $grantable, $granted) {
            $changed = 0;

            foreach ($roles as $role) {
                $current = $role->permissions->pluck('name')->all();
                $delta = $granted
                    ? array_values(array_diff($grantable, $current))
                    : array_values(array_intersect($grantable, $current));

                if ($delta === []) {
                    continue;
                }

                $granted ? $role->givePermissionTo($delta) : $role->revokePermissionTo($delta);
                sort($delta);

                ActivityLog::record($role, 'updated', $granted
                    ? ['permissions_granted' => [null, implode(', ', $delta)]]
                    : ['permissions_revoked' => [implode(', ', $delta), null]]);
                $changed++;
            }

            return $changed;
        });
    }

    /**
     * @throws ValidationException If the role is the superadmin or the actor may not manage it.
     */
    private function ensureRoleEditable(Role $role, ?User $actor): void
    {
        if (RbacGuard::isSuperadminRole($role)) {
            throw ValidationException::withMessages([
                'matrix' => 'Права роли «'.$role->name.'» нельзя изменять',
            ]);
        }

        if (! RbacGuard::canManageRole($actor, $role)) {
            throw ValidationException::withMessages([
                'matrix' => $role->is_system || $actor === null
                    ? 'Права системной роли может менять только администратор'
                    : 'У роли «'.$role->name.'» есть права, которых нет у вас: '.RbacGuard::missingPermissions($actor, $role)->implode(', '),
            ]);
        }
    }
}
