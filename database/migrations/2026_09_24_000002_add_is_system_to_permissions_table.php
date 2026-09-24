<?php

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the permissions the application code depends on (route middleware,
 * FormRequest checks) as system permissions: they cannot be renamed or deleted
 * from the admin panel, otherwise a single click could lock everyone out.
 */
return new class extends Migration
{
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
            ->whereIn('name', RolePermissionSeeder::PERMISSIONS)
            ->update(['is_system' => true]);
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }
};
