<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The notification bell was removed from the panel: its read marker and the
 * muted categories are no longer stored. The activity log stays.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = array_values(array_filter(
            ['notifications_seen_at', 'notification_mutes'],
            fn (string $column) => Schema::hasColumn('users', $column),
        ));

        if ($columns !== []) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('notifications_seen_at')->nullable()->after('last_login_at');
            $table->json('notification_mutes')->nullable()->after('notifications_seen_at');
        });
    }
};
