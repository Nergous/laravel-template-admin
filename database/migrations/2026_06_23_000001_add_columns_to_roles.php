<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Доменные расширения ролей. Таблицу roles создаёт пакет spatie/laravel-permission
 * (create_permission_tables), поэтому здесь ALTER, а не create — vendor-миграцию
 * не правим.
 */
return new class extends Migration
{
    private function table(): string
    {
        return config('permission.table_names.roles', 'roles');
    }

    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table) {
            // Человеко-читаемое описание роли (показывается на карточке роли).
            $table->string('description')->nullable()->after('guard_name');
            // Системные роли (admin/operator) — нельзя удалять/переименовывать.
            $table->boolean('is_system')->default(false)->after('description');
            // Авторство.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        });

        if (! $this->innodbAutoIndexesForeignKeys()) {
            Schema::table($this->table(), function (Blueprint $table) {
                $table->index('created_by');
                $table->index('updated_by');
            });
        }
    }

    public function down(): void
    {
        if (! $this->innodbAutoIndexesForeignKeys()) {
            Schema::table($this->table(), function (Blueprint $table) {
                $table->dropIndex(['created_by']);
                $table->dropIndex(['updated_by']);
            });
        }

        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('updated_by');
            $table->dropColumn(['description', 'is_system']);
        });
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
