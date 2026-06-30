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
 * Доменные операции над разрешениями: тумблер матрицы «роль × право» и CRUD
 * над самими правами, с анти-эскалацией и журналированием.
 */
class PermissionService
{
    /**
     * Переключение одной ячейки матрицы: выдать/снять разрешение у роли.
     *
     * @param  int  $roleId  Идентификатор роли
     * @param  string  $permission  Имя разрешения (модуль.действие)
     * @param  bool  $granted  true — выдать право роли, false — снять
     *
     * @throws ValidationException Если роль заблокирована или нарушается анти-эскалация.
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
     * Создаёт новое разрешение (guard web), авто-выдаёт его системной роли admin
     * и логирует оба действия.
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

    /** Переименовывает разрешение и логирует изменение имени. */
    public function update(Permission $permission, string $name): Permission
    {
        $old = $permission->name;
        $permission->update(['name' => $name]);
        ActivityLog::record($permission, 'updated', $old !== $permission->name ? ['name' => [$old, $permission->name]] : null);

        return $permission;
    }

    /** Удаляет разрешение с записью в журнал. */
    public function delete(Permission $permission): void
    {
        ActivityLog::record($permission, 'deleted');
        $permission->delete();
    }
}
