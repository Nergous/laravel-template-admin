<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Доменные операции над ролями: создание/обновление с набором прав и удаление,
 * с журналированием и защитой системных ролей.
 */
class RoleService
{
    /**
     * Создаёт роль, назначает права и логирует (в транзакции).
     *
     * @param  array{name: string, description?: string|null}  $data
     * @param  array<int, string>  $permissions  Имена разрешений.
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
     * Обновляет роль и её права (имя системной роли менять нельзя), логирует дельту.
     *
     * @param  array{name: string, description?: string|null}  $data
     * @param  array<int, string>  $permissions
     *
     * @throws ValidationException Если меняется имя системной роли.
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
     * Удаляет роль (нельзя системную и назначенную пользователям), логирует.
     *
     * @throws ValidationException Если роль системная или назначена пользователям.
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
