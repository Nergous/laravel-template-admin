<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The forced password change on the next login was removed from the panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'must_change_password')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('must_change_password'));
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('must_change_password')->default(false)->after('is_active'));
    }
};
