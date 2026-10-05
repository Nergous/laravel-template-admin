<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Support\RbacGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Domain operations on roles: create/update with a set of permissions and
 * deletion, with logging, protection of system roles and the anti-escalation
 * rules of App\Support\RbacGuard (the superadmin role's permissions are
 * immutable; a role is changed only by someone who holds all its permissions).
 */
class RoleService
{
    /**
     * Creates a role, assigns permissions and logs it (in a transaction).
     *
     * @param  array{name: string, description?: string|null}  $data
     * @param  array<int, string>  $permissions  Permission names.
     */
    public function create(array $data, array $permissions, ?User $actor): Role
    {
        return DB::transaction(function () use ($data, $permissions, $actor) {
            $role = Role::create([
                'name' => $data['name'],
                'guard_name' => 'web',
                'description' => $data['description'] ?? null,
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ]);

            $role->syncPermissions($permissions);
            ActivityLog::record($role, 'created');

            return $role;
        });
    }

    /**
     * Updates a role and, when $permissions is given, its permission set (a
     * system role's name cannot be changed), logs the delta. A null
     * $permissions keeps the current set.
     *
     * @param  array{name: string, description?: string|null}  $data
     * @param  array<int, string>|null  $permissions
     *
     * @throws ValidationException If a system role's name is changed or the actor may not make the change.
     */
    public function update(Role $role, array $data, ?array $permissions, ?User $actor): Role
    {
        if ($role->is_system && $data['name'] !== $role->name) {
            throw ValidationException::withMessages([
                'name' => 'Имя системной роли нельзя менять',
            ]);
        }

        $this->ensureCanChange($role, $permissions, $actor);

        $before = ['name' => $role->name, 'description' => $role->description];
        $permsBefore = $role->permissions->pluck('name')->all();

        return DB::transaction(function () use ($role, $data, $permissions, $actor, $before, $permsBefore) {
            $role->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'updated_by' => $actor?->id,
            ]);

            if ($permissions !== null) {
                $role->syncPermissions($permissions);
            }

            $changes = [];
            foreach ($before as $field => $old) {
                if ($old !== $role->{$field}) {
                    $changes[$field] = [$old, $role->{$field}];
                }
            }

            $permsAfter = $role->permissions()->pluck('name')->all();
            $granted = array_values(array_diff($permsAfter, $permsBefore));
            $revoked = array_values(array_diff($permsBefore, $permsAfter));
            sort($granted);
            sort($revoked);
            if ($granted !== []) {
                $changes['permissions_granted'] = [null, implode(', ', $granted)];
            }
            if ($revoked !== []) {
                $changes['permissions_revoked'] = [implode(', ', $revoked), null];
            }

            ActivityLog::record($role, 'updated', $changes ?: null);

            return $role;
        });
    }

    /**
     * @param  array<int, string>|null  $permissions
     *
     * @throws ValidationException If the actor may not manage the role, the superadmin
     *                             role's permissions would change, or a permission the
     *                             actor lacks would be granted.
     */
    private function ensureCanChange(Role $role, ?array $permissions, ?User $actor): void
    {
        if (! RbacGuard::canManageRole($actor, $role)) {
            throw ValidationException::withMessages([
                'role' => $role->is_system
                    ? 'Системную роль может менять только администратор'
                    : 'У роли есть права, которых нет у вас: её может менять только пользователь со всеми этими правами',
            ]);
        }

        if ($permissions === null) {
            return;
        }

        $current = $role->permissions->pluck('name')->all();
        $added = array_values(array_diff($permissions, $current));

        if (RbacGuard::isSuperadminRole($role) && ($added !== [] || array_diff($current, $permissions) !== [])) {
            throw ValidationException::withMessages([
                'permissions' => 'Права роли «'.$role->name.'» нельзя изменять',
            ]);
        }

        $denied = array_values(array_filter($added, fn (string $permission) => ! RbacGuard::canGrantPermission($actor, $permission)));

        if ($denied !== []) {
            throw ValidationException::withMessages([
                'permissions' => 'Нельзя назначить роли право, которого у вас нет: '.implode(', ', $denied),
            ]);
        }
    }

    /**
     * Deletes a role (a system role or one assigned to users cannot be deleted), logs it.
     *
     * @throws ValidationException If the role is a system role or is assigned to users.
     */
    public function delete(Role $role): void
    {
        if ($role->is_system) {
            throw ValidationException::withMessages([
                'role' => 'Системную роль нельзя удалить',
            ]);
        }

        if ($role->users()->exists()) {
            throw ValidationException::withMessages([
                'role' => 'Нельзя удалить роль, назначенную пользователям',
            ]);
        }

        // Log only a deletion that actually happened.
        DB::transaction(function () use ($role) {
            $role->delete();
            ActivityLog::record($role, 'deleted');
        });
    }
}
