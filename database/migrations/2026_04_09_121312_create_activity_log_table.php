<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The activity_log table — activity log (audit).
 *
 * Polymorphic subject (nullableMorphs), JSON diff of changes and a manual created_at
 * without updated_at. Model App\Models\ActivityLog, written via the LogsActivity trait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');              // created, updated, deleted, restored, duplicated, ...
            $table->nullableMorphs('subject');      // subject_type + subject_id (+ their index)
            $table->string('subject_label')->nullable(); // snapshot of the entity name at the time of the action
            $table->string('actor_label')->nullable();   // snapshot of the author name: survives a force-delete
            $table->json('changes')->nullable();    // {"title": ["old", "new"], ...}
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');                   // log without a filter (latest('created_at'))
            $table->index(['action', 'created_at']);       // filter by action + sort
            $table->index(['subject_type', 'created_at']); // filter by subject type + sort
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
