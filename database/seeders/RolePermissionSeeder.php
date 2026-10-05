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
     * delete them. Add your own "module.action" permissions here (and a
     * migration for existing installations, see App\Support\Migrations\PermissionMigration).
     *
     * @var list<string>
     */
    public const PERMISSIONS = [
        'users.view', 'users.create', 'users.edit', 'users.delete', 'users.export', 'users.impersonate',
        'roles.view', 'roles.create', 'roles.edit', 'roles.delete',
        'permissions.view', 'permissions.edit',
        'media.view', 'media.upload', 'media.edit', 'media.delete',
        'activity-log.view', 'activity-log.delete',
        'settings.view', 'settings.edit',
        'backups.view', 'backups.download', 'backups.create', 'backups.delete',
        'queue.view', 'queue.manage',
    ];

    /**
     * Default permissions of the operator role.
     *
     * @var list<string>
     */
    public const OPERATOR_PERMISSIONS = [
        'media.view', 'media.upload', 'media.edit', 'media.delete',
    ];

    /**
     * Base roles and permissions of a NEW installation (db:seed, app:seed-fresh
     * on an empty database).
     *
     * - admin     — all permissions.
     * - operator  — media library.
     *
     * Destructive for existing roles: both are reset to these defaults (syncPermissions),
     * dropping whatever was configured in the admin panel. Code that runs on a
     * live database (app:create-admin) uses ensure() instead.
     *
     * Add your own permissions following the "module.action" scheme and group them by prefix
     * (module) — the roles UI draws checkboxes grouped by this prefix.
     */
    public function run(): void
    {
        $this->createPermissions();

        $this->systemRole(RbacGuard::superadminRole(), 'Полный доступ ко всем разделам панели.')
            ->syncPermissions(self::PERMISSIONS);
        $this->systemRole('operator', 'Управление медиатекой: загрузка, переименование и удаление файлов.')
            ->syncPermissions(self::OPERATOR_PERMISSIONS);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Non-destructive variant for a live database: creates missing permissions
     * and roles and grants the superadmin any permission it lacks (it must hold
     * all of them, see RbacGuard). An existing operator or any other role keeps
     * exactly the permissions configured in the admin panel; a role created
     * here starts with its default set.
     */
    public function ensure(): void
    {
        $this->createPermissions();

        $admin = Role::where('name', RbacGuard::superadminRole())->where('guard_name', 'web')->first()
            ?? $this->systemRole(RbacGuard::superadminRole(), 'Полный доступ ко всем разделам панели.');
        $missing = array_values(array_diff(self::PERMISSIONS, $admin->permissions()->pluck('name')->all()));
        if ($missing !== []) {
            $admin->givePermissionTo($missing);
        }

        if (! Role::where('name', 'operator')->where('guard_name', 'web')->exists()) {
            $this->systemRole('operator', 'Управление медиатекой: загрузка, переименование и удаление файлов.')
                ->syncPermissions(self::OPERATOR_PERMISSIONS);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createPermissions(): void
    {
        foreach (self::PERMISSIONS as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);

            if (! $permission->is_system) {
                $permission->is_system = true;
                $permission->save();
            }
        }
    }

    /**
     * Finds or creates a protected role. The superadmin name comes from
     * config('rbac.superadmin_role'), so renaming the superadmin is set in one place.
     */
    private function systemRole(string $name, string $description): Role
    {
        $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        // is_system is protected from mass-assignment (App\Models\Role) — set it explicitly.
        $role->description = $description;
        $role->is_system = true;
        $role->save();

        return $role;
    }
}
