<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Migration behaviour that the rest of the suite does not exercise: rollbacks
 * and the data migrations. Runs on SQLite :memory: like every test; CI repeats
 * the full migrate/rollback cycle on MariaDB (.github/workflows/ci.yml).
 */
class MigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_migration_rolls_back_and_applies_again(): void
    {
        $this->artisan('migrate:reset', ['--force' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('users'));

        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertTrue(Schema::hasTable('media_folders'));
    }

    public function test_foreign_key_columns_are_indexed(): void
    {
        foreach ([
            'media_folders' => ['created_by'],
            'activity_log' => ['impersonator_id'],
        ] as $table => $columns) {
            $leading = array_map(fn (array $index) => $index['columns'][0], Schema::getIndexes($table));

            foreach ($columns as $column) {
                $this->assertContains($column, $leading, "{$table}.{$column}");
            }
        }
    }

    public function test_unused_permissions_are_removed_with_their_assignments(): void
    {
        $this->seedRolesAndPermissions();
        $created = Permission::create(['name' => 'permissions.create', 'guard_name' => 'web']);
        $deleted = Permission::create(['name' => 'permissions.delete', 'guard_name' => 'web']);
        Role::findByName('admin')->givePermissionTo([$created, $deleted]);

        $this->migration('2026_10_05_000004_remove_unused_permissions.php')->up();

        $this->assertDatabaseMissing('permissions', ['name' => 'permissions.create']);
        $this->assertDatabaseMissing('permissions', ['name' => 'permissions.delete']);
        $this->assertSame(0, DB::table('role_has_permissions')->whereIn('permission_id', [$created->id, $deleted->id])->count());
        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('permissions.edit'));
    }

    public function test_media_folders_rollback_refuses_to_cut_long_folder_paths(): void
    {
        $long = str_repeat('Папка/', 20);
        DB::table('media')->insert([
            'filename' => 'media/a.jpg', 'original_name' => 'a.jpg', 'mime_type' => 'image/jpeg',
            'type' => 'image', 'size' => 1, 'folder' => $long, 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $this->migration('2026_10_02_000001_create_media_folders_table.php')->down();
            $this->fail('The rollback must refuse a folder longer than 100 characters.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('longer than 100 characters', $e->getMessage());
        }

        $this->assertTrue(Schema::hasTable('media_folders'));
        $this->assertSame($long, DB::table('media')->value('folder'));
    }

    private function migration(string $file): object
    {
        return require database_path('migrations/'.$file);
    }
}
