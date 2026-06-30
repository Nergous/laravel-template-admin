<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Base authentication tables (users, password_reset_tokens, sessions) with
 * domain extensions for users.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->softDeletes();

            $table->string('email_active')
                ->storedAs('(CASE WHEN deleted_at IS NULL THEN email ELSE NULL END)');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique('email_active', 'users_email_unique');
            $table->index('name');        // sort the list by name
            $table->index('created_at');  // sort by registration date
            $table->index('deleted_at');  // trash predicate WHERE deleted_at IS NOT NULL (L1)
        });

        if (! $this->innodbAutoIndexesForeignKeys()) {
            Schema::table('users', function (Blueprint $table) {
                $table->index('created_by');
                $table->index('updated_by');
            });
        }

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }

    private function innodbAutoIndexesForeignKeys(): bool
    {
        return in_array(
            Schema::getConnection()->getDriverName(),
            ['mysql', 'mariadb'],
            true
        );
    }
};
