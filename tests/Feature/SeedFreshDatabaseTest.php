<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeedFreshDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_database_is_seeded_once_and_existing_permissions_survive_restart(): void
    {
        $this->artisan('app:seed-fresh')->assertSuccessful();

        $operator = Role::where('name', 'operator')->firstOrFail();
        $operator->syncPermissions([]);

        $this->artisan('app:seed-fresh')->assertSuccessful();

        $this->assertSame(0, $operator->fresh()->permissions()->count());
        $this->assertDatabaseHas('settings', [
            'key' => 'system.initial_seed',
            'value' => 'complete',
        ]);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_existing_application_data_is_not_seeded(): void
    {
        User::factory()->create();

        $this->artisan('app:seed-fresh', ['--users' => true])->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('roles', 0);
        $this->assertDatabaseMissing('settings', ['key' => 'system.initial_seed']);
    }

    public function test_users_are_created_only_on_initial_seed_when_requested(): void
    {
        $this->artisan('app:seed-fresh', ['--users' => true])->assertSuccessful();

        $this->assertDatabaseCount('users', 2);

        $this->artisan('app:seed-fresh', ['--users' => true])->assertSuccessful();

        $this->assertDatabaseCount('users', 2);
    }

    public function test_pending_marker_retries_initial_seed(): void
    {
        DB::table('settings')->insert([
            'group' => 'system',
            'key' => 'system.initial_seed',
            'type' => 'string',
            'value' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('app:seed-fresh')->assertSuccessful();

        $this->assertDatabaseHas('roles', ['name' => 'operator']);
        $this->assertDatabaseHas('settings', [
            'key' => 'system.initial_seed',
            'value' => 'complete',
        ]);
    }
}
