<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Support\RbacGuard;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Permissions the application code relies on. They are seeded as system
     * permissions (permissions.is_system): the admin panel cannot rename or
     * delete them. Add your own "module.action" permissions here.
     *
     * @var list<string>
     */
    public const PERMISSIONS = [
        'users.view', 'users.create', 'users.edit', 'users.delete',
        'roles.view', 'roles.create', 'roles.edit', 'roles.delete',
        'permissions.view', 'permissions.create', 'permissions.edit', 'permissions.delete',
        'media.view', 'media.upload', 'media.edit', 'media.delete',
        'activity-log.view', 'activity-log.delete',
        'settings.view', 'settings.edit',
        'backups.view', 'backups.create',
    ];

    /**
     * Base roles and permissions of the template.
     *
     * - admin     — all permissions.
     * - operator  — media library.
     *
     * Add your own permissions following the "module.action" scheme and group them by prefix
     * (module) — the roles UI draws checkboxes grouped by this prefix.
     */
    public function run(): void
    {
        $permissions = self::PERMISSIONS;

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);

            if (! $permission->is_system) {
                $permission->is_system = true;
                $permission->save();
            }
        }

        // The superadmin name comes from config('rbac.superadmin_role'),
        // so renaming the superadmin is set in one place.
        $admin = Role::firstOrCreate(['name' => RbacGuard::superadminRole(), 'guard_name' => 'web']);
        // is_system is protected from mass-assignment (App\Models\Role) — set it explicitly.
        $admin->description = 'Полный доступ ко всем разделам панели.';
        $admin->is_system = true;
        $admin->save();
        $admin->syncPermissions($permissions);

        $operator = Role::firstOrCreate(['name' => 'operator', 'guard_name' => 'web']);
        $operator->description = 'Управление медиатекой: загрузка, переименование и удаление файлов.';
        $operator->is_system = true;
        $operator->save();
        $operator->syncPermissions([
            'media.view', 'media.upload', 'media.edit', 'media.delete',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
