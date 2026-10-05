<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_renders_inertia_page(): void
    {
        Permission::findOrCreate('permissions.view', 'web');
        $user = User::factory()->create();
        $user->givePermissionTo('permissions.view');

        $this->actingAs($user)
            ->get('/admin/permissions')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Permissions/Index')
                ->has('roles')
                ->has('groups')
                ->has('matrix')
            );
    }

    public function test_matrix_eager_loads_role_permissions_without_n_plus_1(): void
    {
        Permission::findOrCreate('permissions.view', 'web');

        foreach (['alpha', 'beta', 'gamma', 'delta', 'epsilon'] as $name) {
            Role::findOrCreate($name, 'web')->givePermissionTo('permissions.view');
        }

        $user = User::factory()->create();
        $user->givePermissionTo('permissions.view');

        DB::enableQueryLog();
        $this->actingAs($user)->get('/admin/permissions')->assertOk();
        $pivotQueries = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'role_has_permissions'))
            ->count();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(2, $pivotQueries);
    }

    public function test_permissions_cannot_be_created_from_the_panel(): void
    {
        $this->actingAsAdmin();

        $this->assertFalse(Route::has('admin.permissions.store'));
        $this->assertFalse(Route::has('admin.permissions.create'));
        $this->post('/admin/permissions', ['name' => 'demo.new'])->assertStatus(405);

        $this->assertDatabaseMissing('permissions', ['name' => 'demo.new']);
    }

    public function test_permissions_cannot_be_renamed_or_deleted_from_the_panel(): void
    {
        $this->actingAsAdmin();
        $permission = Permission::findByName('users.view', 'web');

        foreach (['show', 'edit', 'update', 'destroy'] as $action) {
            $this->assertFalse(Route::has("admin.permissions.{$action}"));
        }

        $this->put('/admin/permissions/'.$permission->id, ['name' => 'users.renamed'])->assertNotFound();
        $this->delete('/admin/permissions/'.$permission->id)->assertNotFound();

        $this->assertDatabaseHas('permissions', ['id' => $permission->id, 'name' => 'users.view']);
    }

    public function test_matrix_toggle_invalidates_permission_cache(): void
    {
        $this->actingAsAdmin();

        $editor = Role::findOrCreate('editor', 'web');
        $bob = User::factory()->create();
        $bob->assignRole('editor');

        $this->assertFalse($bob->hasPermissionTo('users.view'));

        $this->patch(route('admin.permissions.sync'), [
            'role_id' => $editor->id,
            'permission' => 'users.view',
            'granted' => true,
        ])->assertRedirect();

        $this->assertTrue($bob->fresh()->hasPermissionTo('users.view'));

        $this->patch(route('admin.permissions.sync'), [
            'role_id' => $editor->id,
            'permission' => 'users.view',
            'granted' => false,
        ])->assertRedirect();

        $this->assertFalse($bob->fresh()->hasPermissionTo('users.view'));
    }
}
