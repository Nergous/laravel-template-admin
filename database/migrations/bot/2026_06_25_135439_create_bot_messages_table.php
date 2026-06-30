<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица bot_messages — переопределения текстов бота из админки.
 * Коды и дефолты живут в modules/max-bot/messages.json; здесь только override.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_messages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 128)->unique();
            $table->text('text');
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        if (! $this->innodbAutoIndexesForeignKeys()) {
            Schema::table('bot_messages', function (Blueprint $table) {
                $table->index('updated_by');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_messages');
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
