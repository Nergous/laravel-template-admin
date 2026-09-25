<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * blocked_reason explains a block to other admins and to the blocked user;
 * notification_mutes lists the bell categories the user chose to hide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('blocked_reason')->nullable()->after('is_active');
            $table->json('notification_mutes')->nullable()->after('notifications_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['blocked_reason', 'notification_mutes']);
        });
    }
};
