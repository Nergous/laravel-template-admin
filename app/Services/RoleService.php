<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Domain operations on roles: create/update with a set of permissions and
 * deletion, with logging and protection of system roles.
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
     * Updates a role and its permissions (a system role's name cannot be changed),
     * logs the delta.
     *
     * @param  array{name: string, description?: string|null}  $data
     * @param  array<int, string>  $permissions
     *
     * @throws ValidationException If a system role's name is changed.
     */
    public function update(Role $role, array $data, array $permissions, ?User $actor): Role
    {
        if ($role->is_system && $data['name'] !== $role->name) {
            throw ValidationException::withMessages([
                'name' => 'Имя системной роли нельзя менять',
            ]);
        }

        $before = ['name' => $role->name, 'description' => $role->description];
        $permsBefore = $role->permissions->pluck('name')->all();

        return DB::transaction(function () use ($role, $data, $permissions, $actor, $before, $permsBefore) {
            $role->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'updated_by' => $actor?->id,
            ]);
            $role->syncPermissions($permissions);

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

        ActivityLog::record($role, 'deleted');
        $role->delete();
    }
}
