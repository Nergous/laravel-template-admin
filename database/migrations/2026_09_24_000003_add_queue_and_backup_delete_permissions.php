<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds the system permissions introduced after the first release (queue
 * management, backup deletion) to installations that already have the RBAC
 * catalog, and grants them to the superadmin role.
 *
 * A fresh database is left untouched: RolePermissionSeeder creates the whole
 * catalog, and app:seed-fresh treats any existing row as application data.
 */
return new class extends Migration
{
    private const PERMISSIONS = ['backups.delete', 'queue.view', 'queue.manage'];

    public function up(): void
    {
        $permissions = config('permission.table_names.permissions', 'permissions');
        $roles = config('permission.table_names.roles', 'roles');
        $pivot = config('permission.table_names.role_has_permissions', 'role_has_permissions');

        if (! DB::table($permissions)->exists()) {
            return;
        }

        $now = now();
        $superadminId = DB::table($roles)
            ->where('name', config('rbac.superadmin_role', 'admin'))
            ->where('guard_name', 'web')
            ->value('id');

        foreach (self::PERMISSIONS as $name) {
            $id = DB::table($permissions)->where('name', $name)->where('guard_name', 'web')->value('id')
                ?? DB::table($permissions)->insertGetId([
                    'name' => $name,
                    'guard_name' => 'web',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            DB::table($permissions)->where('id', $id)->update(['is_system' => true]);

            if ($superadminId !== null) {
                DB::table($pivot)->insertOrIgnore(['permission_id' => $id, 'role_id' => $superadminId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // The permissions may already be assigned to custom roles; keep them.
    }
};
