<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records who was really behind an action performed in "sign in as" mode:
 * user_id stays the impersonated account, impersonator_id is the superadmin.
 * impersonator_label is a name snapshot that survives a force-delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->foreignId('impersonator_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            $table->string('impersonator_label')->nullable()->after('actor_label');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropConstrainedForeignId('impersonator_id');
            $table->dropColumn('impersonator_label');
        });
    }
};
