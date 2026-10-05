<?php

namespace App\Support\Migrations;

use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Adds system permissions introduced after the first release to installations
 * that already have the RBAC catalog. Shared by the permission migrations
 * (2026_09_24_000003, 2026_09_25_000003, 2026_09_25_000005, 2026_09_28_000004).
 *
 * Applied migrations call this code, so its behaviour must never change: write
 * a new method for different behaviour.
 *
 * A fresh database (no permissions yet) is left untouched: RolePermissionSeeder
 * creates the whole catalog, and app:seed-fresh treats any existing row as
 * application data. Otherwise each permission is created (or found), marked as
 * system and granted to the superadmin role plus every role holding the
 * permission it is inherited from, so existing roles keep their abilities.
 */
final class PermissionMigration
{
    /**
     * @param  array<string, string|null>  $permissions  New permission => the permission whose
     *                                                   holders inherit it (null — superadmin only)
     */
    public static function add(array $permissions): void
    {
        $permissionsTable = config('permission.table_names.permissions', 'permissions');
        $rolesTable = config('permission.table_names.roles', 'roles');
        $pivot = config('permission.table_names.role_has_permissions', 'role_has_permissions');

        if (! DB::table($permissionsTable)->exists()) {
            return;
        }

        $now = now();
        $superadminId = DB::table($rolesTable)
            ->where('name', config('rbac.superadmin_role', 'admin'))
            ->where('guard_name', 'web')
            ->value('id');

        foreach ($permissions as $name => $inheritedFrom) {
            $id = DB::table($permissionsTable)->where('name', $name)->where('guard_name', 'web')->value('id')
                ?? DB::table($permissionsTable)->insertGetId([
                    'name' => $name,
                    'guard_name' => 'web',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            DB::table($permissionsTable)->where('id', $id)->update(['is_system' => true]);

            $roleIds = $inheritedFrom === null ? collect() : DB::table($pivot)
                ->join($permissionsTable, "{$permissionsTable}.id", '=', "{$pivot}.permission_id")
                ->where("{$permissionsTable}.name", $inheritedFrom)
                ->where("{$permissionsTable}.guard_name", 'web')
                ->pluck("{$pivot}.role_id");

            if ($superadminId !== null) {
                $roleIds->push($superadminId);
            }

            foreach ($roleIds->unique() as $roleId) {
                DB::table($pivot)->insertOrIgnore(['permission_id' => $id, 'role_id' => $roleId]);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
