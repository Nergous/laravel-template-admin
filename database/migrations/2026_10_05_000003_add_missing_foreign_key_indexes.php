<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for foreign key columns that were created without one. MySQL/MariaDB
 * index every foreign key on their own, SQLite and PostgreSQL do not: there
 * deleting a user scans these tables for the nullOnDelete update, and lookups
 * by these columns are full scans.
 *
 * A column that already leads an index (the automatic MySQL one included) is
 * skipped; down() drops only the indexes this migration created.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const COLUMNS = [
        'media_folders' => ['created_by'],
        'activity_log' => ['impersonator_id'],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column) && ! $this->isIndexed($table, $column)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($column, $this->indexName($table, $column)));
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                $name = $this->indexName($table, $column);

                if (Schema::hasTable($table) && Schema::hasIndex($table, $name)) {
                    Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
                }
            }
        }
    }

    /** Whether some index starts with the column (it serves lookups by it alone). */
    private function isIndexed(string $table, string $column): bool
    {
        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'][0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    private function indexName(string $table, string $column): string
    {
        return "{$table}_{$column}_index";
    }
};
