<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account state for users: blocking, the last successful login, a forced
 * password change, and the moment the notification bell was last opened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('password');
            $table->boolean('must_change_password')->default(false)->after('is_active');
            $table->timestamp('last_login_at')->nullable()->after('must_change_password');
            $table->timestamp('notifications_seen_at')->nullable()->after('last_login_at');

            $table->index('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['last_login_at']);
            $table->dropColumn(['is_active', 'must_change_password', 'last_login_at', 'notifications_seen_at']);
        });
    }
};
