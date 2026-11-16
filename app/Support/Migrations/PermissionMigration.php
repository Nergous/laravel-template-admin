<?php

namespace App\Support\Migrations;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Shared code of permission migrations: adds new permissions to installations
 * that already have the RBAC catalog and renames existing ones.
 *
 * Applied migrations call this code, so its behaviour must not change: write a
 * new method for different behaviour.
 */
final class PermissionMigration
{
    private const GUARD = 'web';

    /**
     * Adds permissions and grants them to the superadmin and the inheriting roles.
     *
     * An empty permissions table is left alone: RolePermissionSeeder creates the
     * whole catalog, and app:seed-fresh treats any existing row as application data.
     * Otherwise each permission is created (or found by name) and granted to the
     * superadmin role and to every role holding the permission it inherits from,
     * so existing roles keep their abilities.
     *
     * @param  array<string, string|null>  $permissions  New permission => permission whose holders inherit it
     *                                                   (null — the superadmin only)
     */
    public static function add(array $permissions): void
    {
        $tables = self::tables();

        if (! DB::table($tables['permissions'])->exists()) {
            return;
        }

        DB::transaction(function () use ($permissions, $tables): void {
            $now = now();

            // The superadmin is looked up once for the whole list.
            $superadminId = DB::table($tables['roles'])
                ->where('name', config('rbac.superadmin_role', 'admin'))
                ->where('guard_name', self::GUARD)
                ->value('id');

            foreach ($permissions as $name => $inheritedFrom) {
                $permissionId = self::findOrCreatePermission($tables, $name, $now);

                $roleIds = self::inheritingRoleIds($tables, $inheritedFrom);

                if ($superadminId !== null) {
                    $roleIds->push($superadminId);
                }

                self::grantToRoles($tables, $permissionId, $roleIds->unique());
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Renames permissions in place so roles and users keep them. If the new name
     * already exists, the old permission's grants move to it and the old row is
     * deleted.
     *
     * @param  array<string, string>  $names  Old name => new name
     */
    public static function rename(array $names): void
    {
        $tables = self::tables();

        DB::transaction(function () use ($names, $tables): void {
            foreach ($names as $from => $to) {
                $oldId = self::findPermissionId($tables, $from);

                if ($oldId === null) {
                    continue;
                }

                $newId = self::findPermissionId($tables, $to);

                if ($newId === null) {
                    DB::table($tables['permissions'])->where('id', $oldId)->update(['name' => $to, 'updated_at' => now()]);

                    continue;
                }

                self::moveGrants($tables, $oldId, $newId);

                DB::table($tables['permissions'])->where('id', $oldId)->delete();
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Table names of the spatie/laravel-permission package, honouring the config.
     *
     * @return array{permissions: string, roles: string, role_has_permissions: string, model_has_permissions: string}
     */
    private static function tables(): array
    {
        return [
            'permissions' => config('permission.table_names.permissions', 'permissions'),
            'roles' => config('permission.table_names.roles', 'roles'),
            'role_has_permissions' => config('permission.table_names.role_has_permissions', 'role_has_permissions'),
            'model_has_permissions' => config('permission.table_names.model_has_permissions', 'model_has_permissions'),
        ];
    }

    /**
     * Returns the id of the named permission in the default guard, or null if it does not exist.
     *
     * @param  array<string, string>  $tables
     */
    private static function findPermissionId(array $tables, string $name): ?int
    {
        $id = DB::table($tables['permissions'])
            ->where('name', $name)
            ->where('guard_name', self::GUARD)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Finds a permission by name or creates it and returns its id.
     *
     * @param  array<string, string>  $tables
     */
    private static function findOrCreatePermission(array $tables, string $name, CarbonInterface $now): int
    {
        return self::findPermissionId($tables, $name) ?? DB::table($tables['permissions'])->insertGetId([
            'name' => $name,
            'guard_name' => self::GUARD,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Collects ids of roles holding the permission the new one inherits from.
     * Returns an empty collection for null.
     *
     * @param  array<string, string>  $tables
     * @return Collection<int, int|string>
     */
    private static function inheritingRoleIds(array $tables, ?string $inheritedFrom): Collection
    {
        if ($inheritedFrom === null) {
            return collect();
        }

        $pivot = $tables['role_has_permissions'];

        return DB::table($pivot)
            ->join($tables['permissions'], "{$tables['permissions']}.id", '=', "{$pivot}.permission_id")
            ->where("{$tables['permissions']}.name", $inheritedFrom)
            ->where("{$tables['permissions']}.guard_name", self::GUARD)
            ->pluck("{$pivot}.role_id");
    }

    /**
     * Grants a permission to a list of roles. Existing pairs are skipped.
     *
     * @param  array<string, string>  $tables
     * @param  Collection<int, int|string>  $roleIds
     */
    private static function grantToRoles(array $tables, int $permissionId, Collection $roleIds): void
    {
        foreach ($roleIds as $roleId) {
            DB::table($tables['role_has_permissions'])->insertOrIgnore([
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]);
        }
    }

    /**
     * Moves the grants of permission $fromId to permission $toId in the role and
     * user tables, then deletes the old grant rows.
     *
     * @param  array<string, string>  $tables
     */
    private static function moveGrants(array $tables, int $fromId, int $toId): void
    {
        $pivots = [
            $tables['role_has_permissions'],
            $tables['model_has_permissions'],
        ];

        foreach ($pivots as $pivot) {
            foreach (DB::table($pivot)->where('permission_id', $fromId)->get() as $row) {
                DB::table($pivot)->insertOrIgnore([...(array) $row, 'permission_id' => $toId]);
            }

            DB::table($pivot)->where('permission_id', $fromId)->delete();
        }
    }
}
