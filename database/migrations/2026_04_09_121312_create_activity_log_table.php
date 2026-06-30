<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Таблица activity_log — журнал действий (аудит).
 *
 * Полиморфный субъект (nullableMorphs), JSON-diff изменений и ручной created_at
 * без updated_at. Модель App\Models\ActivityLog, запись через трейт LogsActivity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');              // created, updated, deleted, restored, duplicated, ...
            $table->nullableMorphs('subject');      // subject_type + subject_id (+ их индекс)
            $table->string('subject_label')->nullable(); // снимок имени сущности на момент действия
            $table->string('actor_label')->nullable();   // снимок имени автора: переживает force-delete
            $table->json('changes')->nullable();    // {"title": ["old", "new"], ...}
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');                   // журнал без фильтра (latest('created_at'))
            $table->index(['action', 'created_at']);       // фильтр по действию + сортировка
            $table->index(['subject_type', 'created_at']); // фильтр по типу субъекта + сортировка
        });

        if (! $this->innodbAutoIndexesForeignKeys()) {
            Schema::table('activity_log', function (Blueprint $table) {
                $table->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
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
