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
        $permissions = [
            'users.view', 'users.create', 'users.edit', 'users.delete',
            'roles.view', 'roles.create', 'roles.edit', 'roles.delete',
            'permissions.view', 'permissions.create', 'permissions.edit', 'permissions.delete',
            'media.view', 'media.upload', 'media.delete',
            'activity-log.view', 'activity-log.delete',
            'settings.view', 'settings.edit',
        ];

        // Bot permissions are seeded only when the bot is enabled. If you enable
        // the bot LATER (BOT_ACTIVE=true / COMPOSE_PROFILES=bot) — re-create the permissions:
        //   php artisan db:seed --class=Database\\Seeders\\RolePermissionSeeder
        // (firstOrCreate is idempotent). Otherwise bot-messages.* won't appear and the bot routes
        // (gated by permission:bot-messages.view) will be unreachable. We deliberately do NOT seed
        // them always: otherwise a "dead" feature's permissions would dangle in the matrix while the bot is off.
        if (config('bot.enabled')) {
            $permissions[] = 'bot-messages.view';
            $permissions[] = 'bot-messages.edit';
        }

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
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
        $operator->description = 'Управление медиатекой: загрузка и удаление файлов.';
        $operator->is_system = true;
        $operator->save();
        $operator->syncPermissions([
            'media.view', 'media.upload', 'media.delete',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
