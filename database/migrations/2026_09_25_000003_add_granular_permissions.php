<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Splits actions that used to ride on a broader permission into their own
 * system permissions: downloading dumps (was backups.view), exporting users
 * (was users.view) and "sign in as" (was superadmin-only).
 *
 * Existing roles keep their current abilities: every role holding the old
 * permission receives the new one; impersonation goes to the superadmin only.
 * A fresh database is left untouched — RolePermissionSeeder creates the catalog.
 */
return new class extends Migration
{
    /** New permission => the permission whose holders inherit it (null — superadmin only). */
    private const PERMISSIONS = [
        'backups.download' => 'backups.view',
        'users.export' => 'users.view',
        'users.impersonate' => null,
    ];

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

        foreach (self::PERMISSIONS as $name => $inheritedFrom) {
            $id = DB::table($permissions)->where('name', $name)->where('guard_name', 'web')->value('id')
                ?? DB::table($permissions)->insertGetId([
                    'name' => $name,
                    'guard_name' => 'web',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            DB::table($permissions)->where('id', $id)->update(['is_system' => true]);

            $roleIds = $inheritedFrom === null ? collect() : DB::table($pivot)
                ->join($permissions, "{$permissions}.id", '=', "{$pivot}.permission_id")
                ->where("{$permissions}.name", $inheritedFrom)
                ->where("{$permissions}.guard_name", 'web')
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

    public function down(): void
    {
        // The permissions may already be assigned to custom roles; keep them.
    }
};
