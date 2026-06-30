<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Support\RbacGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/**
 * Domain operations on permissions: the "role x permission" matrix toggle and
 * CRUD over the permissions themselves, with anti-escalation and logging.
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

        if (RbacGuard::isSuperadminRole($role)) {
            throw ValidationException::withMessages([
                'matrix' => 'Права системной роли «admin» нельзя изменять',
            ]);
        }

        if (! RbacGuard::canManageRole($actor, $role)) {
            throw ValidationException::withMessages([
                'matrix' => 'Права системной роли может менять только администратор',
            ]);
        }

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
     * Creates a new permission (guard web), auto-grants it to the system admin role
     * and logs both actions.
     */
    public function create(string $name): Permission
    {
        return DB::transaction(function () use ($name) {
            $permission = Permission::create([
                'name' => $name,
                'guard_name' => 'web',
            ]);

            ActivityLog::record($permission, 'created');

            /** @var Role|null $adminRole */
            $adminRole = Role::where('name', RbacGuard::superadminRole())->first();
            if ($adminRole) {
                $adminRole->givePermissionTo($permission);
                ActivityLog::record($adminRole, 'updated', [
                    'permission' => [$permission->name, 'выдано'],
                ]);
            }

            return $permission;
        });
    }

    /** Renames a permission and logs the name change. */
    public function update(Permission $permission, string $name): Permission
    {
        $old = $permission->name;
        $permission->update(['name' => $name]);
        ActivityLog::record($permission, 'updated', $old !== $permission->name ? ['name' => [$old, $permission->name]] : null);

        return $permission;
    }

    /** Deletes a permission, recording it in the log. */
    public function delete(Permission $permission): void
    {
        ActivityLog::record($permission, 'deleted');
        $permission->delete();
    }
}
