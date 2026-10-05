<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Removes the permissions that guard nothing any more: "permissions.create"
 * and "permissions.delete". Permissions come from code (RolePermissionSeeder
 * and migrations); the admin panel can neither create nor rename nor delete
 * one, so these two only cluttered the role matrix. Their role and user
 * assignments go with them.
 */
return new class extends Migration
{
    private const NAMES = ['permissions.create', 'permissions.delete'];

    public function up(): void
    {
        $permissions = config('permission.table_names.permissions', 'permissions');
        $roleHasPermissions = config('permission.table_names.role_has_permissions', 'role_has_permissions');
        $modelHasPermissions = config('permission.table_names.model_has_permissions', 'model_has_permissions');
        $pivotKey = config('permission.column_names.permission_pivot_key') ?: 'permission_id';

        $ids = DB::table($permissions)->whereIn('name', self::NAMES)->where('guard_name', 'web')->pluck('id')->all();
        if ($ids === []) {
            return;
        }

        DB::table($roleHasPermissions)->whereIn($pivotKey, $ids)->delete();
        DB::table($modelHasPermissions)->whereIn($pivotKey, $ids)->delete();
        DB::table($permissions)->whereIn('id', $ids)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Brings the permissions back as system permissions held by the superadmin
     * (as the seeder used to create them); other former assignments are not known.
     */
    public function down(): void
    {
        $permissions = config('permission.table_names.permissions', 'permissions');
        $roles = config('permission.table_names.roles', 'roles');
        $roleHasPermissions = config('permission.table_names.role_has_permissions', 'role_has_permissions');
        $pivotKey = config('permission.column_names.permission_pivot_key') ?: 'permission_id';

        $superadminId = DB::table($roles)
            ->where('name', config('rbac.superadmin_role', 'admin'))
            ->where('guard_name', 'web')
            ->value('id');

        // A fresh database without the RBAC catalog stays empty, as with the seeder-driven migrations.
        if ($superadminId === null) {
            return;
        }

        $now = now();

        foreach (self::NAMES as $name) {
            $id = DB::table($permissions)->where('name', $name)->where('guard_name', 'web')->value('id')
                ?? DB::table($permissions)->insertGetId([
                    'name' => $name,
                    'guard_name' => 'web',
                    'is_system' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            DB::table($roleHasPermissions)->insertOrIgnore([$pivotKey => $id, 'role_id' => $superadminId]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
