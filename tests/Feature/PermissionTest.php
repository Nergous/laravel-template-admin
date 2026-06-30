<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    public function test_creating_permission_logs_auto_grant_to_admin_role(): void
    {
        $this->actingAsAdmin();

        $adminRole = Role::findByName('admin', 'web');

        $this->post(route('admin.permissions.store'), [
            'name' => 'demo.new',
        ])->assertRedirect(route('admin.permissions.index'));

        $permission = Permission::findByName('demo.new', 'web');
        $this->assertDatabaseHas('role_has_permissions', [
            'role_id' => $adminRole->id,
            'permission_id' => $permission->id,
        ]);
        $this->assertDatabaseHas('activity_log', [
            'subject_type' => $permission->getMorphClass(),
            'subject_id' => $permission->id,
            'action' => 'created',
        ]);

        $grantLog = ActivityLog::query()
            ->where('subject_type', $adminRole->getMorphClass())
            ->where('subject_id', $adminRole->id)
            ->where('action', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($grantLog);
        $this->assertSame(['demo.new', 'выдано'], $grantLog->changes['permission'] ?? null);
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

    public function test_creating_permission_is_immediately_effective(): void
    {
        $this->actingAsAdmin();

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->assertTrue($admin->hasPermissionTo('users.view'));

        $this->post(route('admin.permissions.store'), ['name' => 'demo.new'])
            ->assertRedirect(route('admin.permissions.index'));

        $this->assertTrue($admin->fresh()->hasPermissionTo('demo.new'));
    }
}
