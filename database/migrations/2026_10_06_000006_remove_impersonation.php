<?php

use App\Support\Migrations\PermissionMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * Removes the "sign in as" feature: the users.impersonate permission with its
 * role and user assignments, the log entries of entering and leaving the mode,
 * and the impersonator columns of the activity log.
 *
 * down() brings back the columns (empty) and the permission for the superadmin;
 * the deleted log entries and former assignments are not restored.
 */
return new class extends Migration
{
    private const PERMISSION = 'users.impersonate';

    private const ACTIONS = ['impersonation_started', 'impersonation_stopped'];

    /** Created by 2026_10_05_000003 on databases that do not index foreign keys themselves. */
    private const INDEX = 'activity_log_impersonator_id_index';

    public function up(): void
    {
        $this->removePermission();

        DB::table('activity_log')->whereIn('action', self::ACTIONS)->delete();

        if (Schema::hasIndex('activity_log', self::INDEX)) {
            Schema::table('activity_log', fn (Blueprint $table) => $table->dropIndex(self::INDEX));
        }

        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropConstrainedForeignId('impersonator_id');
            $table->dropColumn('impersonator_label');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->foreignId('impersonator_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->string('impersonator_label')->nullable()->after('actor_label');
        });

        if (! $this->isIndexed('activity_log', 'impersonator_id')) {
            Schema::table('activity_log', fn (Blueprint $table) => $table->index('impersonator_id', self::INDEX));
        }

        PermissionMigration::add([self::PERMISSION => null]);
    }

    private function removePermission(): void
    {
        $permissions = config('permission.table_names.permissions', 'permissions');
        $pivotKey = config('permission.column_names.permission_pivot_key') ?: 'permission_id';

        $id = DB::table($permissions)->where('name', self::PERMISSION)->where('guard_name', 'web')->value('id');
        if ($id === null) {
            return;
        }

        DB::table(config('permission.table_names.role_has_permissions', 'role_has_permissions'))->where($pivotKey, $id)->delete();
        DB::table(config('permission.table_names.model_has_permissions', 'model_has_permissions'))->where($pivotKey, $id)->delete();
        DB::table($permissions)->where('id', $id)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Whether some index starts with the column (the automatic MySQL foreign key one included). */
    private function isIndexed(string $table, string $column): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'][0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
};
