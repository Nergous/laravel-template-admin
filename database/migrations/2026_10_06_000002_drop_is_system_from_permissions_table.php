<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every permission comes from code (seeders and migrations) and the panel never
 * creates, renames or deletes one, so the "system" flag carried no information.
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('permission.table_names.permissions', 'permissions');

        if (Schema::hasColumn($table, 'is_system')) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('is_system'));
        }
    }

    public function down(): void
    {
        $table = config('permission.table_names.permissions', 'permissions');

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->boolean('is_system')->default(true)->after('guard_name'));
    }
};
