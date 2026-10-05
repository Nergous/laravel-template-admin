<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the permissions the application code depends on (route middleware,
 * FormRequest checks) as system permissions: they cannot be renamed or deleted
 * from the admin panel, otherwise a single click could lock everyone out.
 *
 * The list is frozen as RolePermissionSeeder::PERMISSIONS was when this
 * migration was written: the seeder constant keeps changing,
 * and a migration must do the same thing whenever it runs.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'users.view', 'users.create', 'users.edit', 'users.delete', 'users.export', 'users.impersonate',
        'roles.view', 'roles.create', 'roles.edit', 'roles.delete',
        'permissions.view', 'permissions.create', 'permissions.edit', 'permissions.delete',
        'media.view', 'media.upload', 'media.edit', 'media.delete',
        'activity-log.view', 'activity-log.delete',
        'settings.view', 'settings.edit',
        'backups.view', 'backups.download', 'backups.create', 'backups.delete',
        'queue.view', 'queue.manage',
    ];

    private function table(): string
    {
        return config('permission.table_names.permissions', 'permissions');
    }

    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('guard_name');
        });

        DB::table($this->table())
            ->whereIn('name', self::PERMISSIONS)
            ->update(['is_system' => true]);
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }
};
