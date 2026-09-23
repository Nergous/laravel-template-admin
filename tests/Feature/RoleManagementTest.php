<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_role(): void
    {
        $this->actingAsAdmin();

        $this->post(route('admin.roles.store'), [
            'name' => 'editor',
            'permissions' => [],
        ])->assertRedirect();

        $this->assertDatabaseHas('roles', ['name' => 'editor']);
    }

    public function test_admin_role_cannot_be_deleted(): void
    {
        $this->actingAsAdmin();

        $adminRole = Role::findByName('admin', 'web');

        $this->from(route('admin.roles.index'))
            ->delete(route('admin.roles.destroy', $adminRole))
            ->assertRedirect(route('admin.roles.index'));

        $this->assertDatabaseHas('roles', ['name' => 'admin']);
    }

    public function test_role_update_logs_granted_and_revoked_permissions(): void
    {
        $this->actingAsAdmin();

        Permission::findOrCreate('demo.alpha', 'web');
        Permission::findOrCreate('demo.beta', 'web');
        Permission::findOrCreate('demo.gamma', 'web');

        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $role->syncPermissions(['demo.alpha', 'demo.beta']);

        $this->put(route('admin.roles.update', $role), [
            'name' => 'editor',
            'permissions' => ['demo.alpha', 'demo.gamma'],
        ])->assertRedirect(route('admin.roles.show', $role));

        $log = ActivityLog::query()
            ->where('subject_id', $role->id)
            ->where('action', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame([null, 'demo.gamma'], $log->changes['permissions_granted'] ?? null);
        $this->assertSame(['demo.beta', null], $log->changes['permissions_revoked'] ?? null);
    }

    public function test_role_update_without_permission_change_logs_no_permission_diff(): void
    {
        $this->actingAsAdmin();

        Permission::findOrCreate('demo.alpha', 'web');

        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $role->syncPermissions(['demo.alpha']);

        $this->put(route('admin.roles.update', $role), [
            'name' => 'editor2',
            'permissions' => ['demo.alpha'],
        ])->assertRedirect(route('admin.roles.show', $role));

        $log = ActivityLog::query()
            ->where('subject_id', $role->id)
            ->where('action', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame(['editor', 'editor2'], $log->changes['name'] ?? null);
        $this->assertArrayNotHasKey('permissions_granted', $log->changes ?? []);
        $this->assertArrayNotHasKey('permissions_revoked', $log->changes ?? []);
    }

    public function test_roles_index_renders_inertia_page(): void
    {
        Permission::findOrCreate('roles.view', 'web');
        $user = User::factory()->create();
        $user->givePermissionTo('roles.view');

        $this->actingAs($user)
            ->get('/admin/roles')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Roles/Index')
                ->has('roles.data')
            );
    }

    public function test_role_resource_pages_have_shareable_urls_and_enforce_write_permissions(): void
    {
        $this->actingAsAdmin();
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);

        $this->get(route('admin.roles.show', $role))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Roles/Show')
                ->where('role.id', $role->id)
                ->where('role.name', 'editor')
            );
        $this->get(route('admin.roles.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Roles/FormPage')
                ->where('mode', 'create')
                ->has('allPermissions')
            );
        $this->get(route('admin.roles.edit', $role))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Roles/FormPage')
                ->where('mode', 'edit')
                ->where('role.id', $role->id)
            );

        Permission::findOrCreate('roles.view', 'web');
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('roles.view');
        $this->actingAs($viewer);

        $this->get(route('admin.roles.show', $role))->assertOk();
        $this->get(route('admin.roles.create'))->assertForbidden();
        $this->get(route('admin.roles.edit', $role))->assertForbidden();
    }

    public function test_role_create_redirects_to_the_role_page_and_system_edit_is_admin_only(): void
    {
        $this->actingAsAdmin();

        $response = $this->post(route('admin.roles.store'), [
            'name' => 'shareable',
            'permissions' => [],
        ]);
        $role = Role::findByName('shareable', 'web');
        $response->assertRedirect(route('admin.roles.show', $role));

        $actor = $this->actingAsUserWith(['roles.view', 'roles.edit']);
        $this->actingAs($actor);
        $systemRole = Role::findByName('admin', 'web');
        $this->get(route('admin.roles.edit', $systemRole))->assertForbidden();
    }
}
