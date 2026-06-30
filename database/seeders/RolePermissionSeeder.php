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
     * Базовые роли и разрешения шаблона.
     *
     * - admin     — все разрешения.
     * - operator  — медиатека.
     *
     * Добавляйте свои разрешения по схеме "модуль.действие" и группируйте по префиксу
     * (модуль) — UI ролей рисует чекбоксы группированно по этому префиксу.
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

        // Права бота сидятся только при включённом боте. Если включаете
        // бот ПОЗЖЕ (BOT_ACTIVE=true / COMPOSE_PROFILES=bot) — пересоздать права:
        //   php artisan db:seed --class=Database\\Seeders\\RolePermissionSeeder
        // (firstOrCreate идемпотентен). Иначе bot-messages.* не появятся и роуты бота
        // (гейт permission:bot-messages.view) будут недостижимы. Намеренно НЕ сидим их
        // всегда: иначе права «мёртвой» фичи болтались бы в матрице при выключенном боте.
        if (config('bot.enabled')) {
            $permissions[] = 'bot-messages.view';
            $permissions[] = 'bot-messages.edit';
        }

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // Имя суперадмина — из config('rbac.superadmin_role'),
        // чтобы переименование суперадмина задавалось в одном месте.
        $admin = Role::firstOrCreate(['name' => RbacGuard::superadminRole(), 'guard_name' => 'web']);
        // is_system защищён от mass-assignment (App\Models\Role) — выставляем явно.
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
