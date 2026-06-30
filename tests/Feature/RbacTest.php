<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_permission_cannot_view_users(): void
    {
        $this->actingAsUserWith([]);

        $this->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_user_with_view_permission_can_view_users(): void
    {
        $this->actingAsUserWith(['users.view']);

        $this->get(route('admin.users.index'))->assertOk();
    }

    public function test_viewer_cannot_delete_users(): void
    {
        $this->actingAsUserWith(['users.view']);

        $target = User::factory()->create();

        $this->delete(route('admin.users.destroy', $target))->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $target->id, 'deleted_at' => null]);
    }

    public function test_user_with_delete_permission_can_delete_users(): void
    {
        $this->actingAsUserWith(['users.view', 'users.delete']);

        $target = User::factory()->create();

        $this->delete(route('admin.users.destroy', $target))->assertRedirect();
        $this->assertSoftDeleted('users', ['id' => $target->id]);
    }

    public function test_media_upload_requires_upload_permission(): void
    {
        $this->actingAsUserWith(['media.view']);

        $this->post(route('admin.media.store'))->assertForbidden();
    }

    public function test_media_delete_requires_delete_permission(): void
    {
        $this->actingAsUserWith(['media.view']);

        $this->delete(route('admin.media.bulk-destroy'), ['ids' => [1]])
            ->assertForbidden();
    }

    public function test_user_store_requires_create_not_just_edit(): void
    {
        $this->actingAsUserWith(['users.view', 'users.edit']);

        $this->post(route('admin.users.store'), [
            'name' => 'Nope',
            'email' => 'nope@example.test',
            'password' => 'Password1',
            'roles' => [],
        ])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'nope@example.test']);
    }

    public function test_user_store_allowed_with_create_permission(): void
    {
        $this->actingAsUserWith(['users.view', 'users.create']);

        $this->post(route('admin.users.store'), [
            'name' => 'Yes',
            'email' => 'yes@example.test',
            'password' => 'Password1',
            'roles' => [],
        ])->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', ['email' => 'yes@example.test']);
    }

    public function test_user_update_requires_edit_not_just_create(): void
    {
        $this->actingAsUserWith(['users.view', 'users.create']);
        $target = User::factory()->create(['name' => 'Original']);

        $this->put(route('admin.users.update', $target), [
            'name' => 'Renamed',
            'email' => $target->email,
            'roles' => [],
        ])->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $target->id, 'name' => 'Original']);
    }

    public function test_user_update_allowed_with_edit_permission(): void
    {
        $this->actingAsUserWith(['users.view', 'users.edit']);
        $target = User::factory()->create(['name' => 'Original']);

        $this->put(route('admin.users.update', $target), [
            'name' => 'Renamed',
            'email' => $target->email,
            'roles' => [],
        ])->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', ['id' => $target->id, 'name' => 'Renamed']);
    }

    public function test_user_edit_holder_cannot_grant_admin_role(): void
    {
        $this->seedRolesAndPermissions();
        $this->actingAsUserWith(['users.view', 'users.edit']);
        $target = User::factory()->create();

        $this->put(route('admin.users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'roles' => ['admin'],
        ])->assertSessionHasErrors('roles.0');

        $this->assertFalse($target->fresh()->hasRole('admin'));
    }

    public function test_user_create_holder_cannot_grant_admin_role(): void
    {
        $this->seedRolesAndPermissions();
        $this->actingAsUserWith(['users.view', 'users.create']);

        $this->post(route('admin.users.store'), [
            'name' => 'Escalate',
            'email' => 'escalate@example.test',
            'password' => 'Password1',
            'roles' => ['admin'],
        ])->assertSessionHasErrors('roles.0');

        $this->assertDatabaseMissing('users', ['email' => 'escalate@example.test']);
    }

    public function test_admin_can_assign_admin_role(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->create();

        $this->put(route('admin.users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'roles' => ['admin'],
        ])->assertRedirect(route('admin.users.index'));

        $this->assertTrue($target->fresh()->hasRole('admin'));
    }

    public function test_non_admin_cannot_assign_system_role_even_holding_its_permissions(): void
    {
        $this->seedRolesAndPermissions();
        $this->actingAsUserWith(['users.view', 'users.edit', 'media.view', 'media.upload', 'media.delete']);
        $target = User::factory()->create();

        $this->put(route('admin.users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'roles' => ['operator'],
        ])->assertSessionHasErrors('roles.0');

        $this->assertFalse($target->fresh()->hasRole('operator'));
    }

    public function test_non_admin_cannot_assign_custom_role_with_permissions_they_lack(): void
    {
        $this->seedRolesAndPermissions();
        $elevated = Role::create(['name' => 'eraser', 'guard_name' => 'web']);
        $elevated->syncPermissions(['users.delete']);

        $this->actingAsUserWith(['users.view', 'users.edit']); // нет users.delete
        $target = User::factory()->create();

        $this->put(route('admin.users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'roles' => ['eraser'],
        ])->assertSessionHasErrors('roles.0');

        $this->assertFalse($target->fresh()->hasRole('eraser'));
    }

    public function test_non_admin_can_assign_custom_role_within_their_permissions(): void
    {
        $this->seedRolesAndPermissions();
        $helper = Role::create(['name' => 'media-helper', 'guard_name' => 'web']);
        $helper->syncPermissions(['media.view']);

        $this->actingAsUserWith(['users.view', 'users.edit', 'media.view', 'media.upload', 'media.delete']);
        $target = User::factory()->create();

        $this->put(route('admin.users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'roles' => ['media-helper'],
        ])->assertRedirect(route('admin.users.index'));

        $this->assertTrue($target->fresh()->hasRole('media-helper'));
    }

    public function test_role_store_requires_create_not_just_edit(): void
    {
        $this->actingAsUserWith(['roles.view', 'roles.edit']); // нет roles.create

        $this->post(route('admin.roles.store'), [
            'name' => 'editor',
            'permissions' => [],
        ])->assertForbidden();

        $this->assertDatabaseMissing('roles', ['name' => 'editor']);
    }

    public function test_role_update_requires_edit_not_just_create(): void
    {
        $this->actingAsUserWith(['roles.view', 'roles.create']); // нет roles.edit
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);

        $this->put(route('admin.roles.update', $role), [
            'name' => 'editor2',
            'permissions' => [],
        ])->assertForbidden();

        $this->assertDatabaseHas('roles', ['id' => $role->id, 'name' => 'editor']);
    }

    public function test_permission_store_requires_create_not_just_edit(): void
    {
        $this->actingAsUserWith(['permissions.view', 'permissions.edit']); // нет create

        $this->post(route('admin.permissions.store'), [
            'name' => 'demo.new',
        ])->assertForbidden();

        $this->assertDatabaseMissing('permissions', ['name' => 'demo.new']);
    }

    public function test_permission_update_requires_edit_not_just_create(): void
    {
        $this->actingAsUserWith(['permissions.view', 'permissions.create']); // нет edit
        $perm = Permission::findOrCreate('demo.old', 'web');

        $this->put(route('admin.permissions.update', $perm), [
            'name' => 'demo.renamed',
        ])->assertForbidden();

        $this->assertDatabaseHas('permissions', ['id' => $perm->id, 'name' => 'demo.old']);
    }

    public function test_permissions_edit_holder_cannot_grant_permission_they_lack_via_matrix(): void
    {
        $this->seedRolesAndPermissions();
        $editor = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $this->actingAsUserWith(['permissions.edit']);

        $this->patch(route('admin.permissions.sync'), [
            'role_id' => $editor->id,
            'permission' => 'users.delete',
            'granted' => true,
        ])->assertSessionHasErrors('matrix');

        $this->assertNotContains('users.delete', $editor->permissions()->pluck('name')->all());
    }

    public function test_matrix_grant_allowed_when_actor_holds_the_permission(): void
    {
        $this->seedRolesAndPermissions();
        $editor = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $this->actingAsUserWith(['permissions.edit', 'media.view']);

        $this->patch(route('admin.permissions.sync'), [
            'role_id' => $editor->id,
            'permission' => 'media.view',
            'granted' => true,
        ])->assertSessionHasNoErrors();

        $this->assertContains('media.view', $editor->permissions()->pluck('name')->all());
    }

    public function test_non_admin_cannot_edit_system_role_via_matrix(): void
    {
        $this->seedRolesAndPermissions();
        $operator = Role::findByName('operator', 'web');
        $this->actingAsUserWith(['permissions.edit', 'media.view']);

        $this->patch(route('admin.permissions.sync'), [
            'role_id' => $operator->id,
            'permission' => 'media.view',
            'granted' => false,
        ])->assertSessionHasErrors('matrix');

        $this->assertContains('media.view', $operator->permissions()->pluck('name')->all());
    }

    public function test_roles_create_holder_cannot_include_permission_they_lack(): void
    {
        $this->seedRolesAndPermissions();
        $this->actingAsUserWith(['roles.view', 'roles.create']);

        $this->post(route('admin.roles.store'), [
            'name' => 'escalator',
            'permissions' => ['users.delete'],
        ])->assertSessionHasErrors('permissions.0');

        $this->assertDatabaseMissing('roles', ['name' => 'escalator']);
    }

    public function test_roles_create_holder_can_include_permissions_they_hold(): void
    {
        $this->seedRolesAndPermissions();
        $this->actingAsUserWith(['roles.view', 'roles.create', 'media.view']);

        $this->post(route('admin.roles.store'), [
            'name' => 'media-helper',
            'permissions' => ['media.view'],
        ])->assertRedirect(route('admin.roles.index'));

        $role = Role::findByName('media-helper', 'web');
        $this->assertContains('media.view', $role->permissions()->pluck('name')->all());
    }

    public function test_roles_edit_holder_cannot_include_permission_they_lack(): void
    {
        $this->seedRolesAndPermissions();
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $this->actingAsUserWith(['roles.view', 'roles.edit']);

        $this->put(route('admin.roles.update', $role), [
            'name' => 'editor',
            'permissions' => ['users.delete'],
        ])->assertSessionHasErrors('permissions.0');

        $this->assertNotContains('users.delete', $role->permissions()->pluck('name')->all());
    }

    public function test_non_admin_cannot_edit_system_role_via_roles_form(): void
    {
        $this->seedRolesAndPermissions();
        $operator = Role::findByName('operator', 'web');
        $this->actingAsUserWith(['roles.view', 'roles.edit', 'media.view', 'media.upload', 'media.delete']);

        $this->put(route('admin.roles.update', $operator), [
            'name' => 'operator',
            'description' => 'hacked',
            'permissions' => ['media.view', 'media.upload', 'media.delete'],
        ])->assertForbidden();

        $this->assertSame(
            'Управление медиатекой: загрузка и удаление файлов.',
            $operator->fresh()->description
        );
    }
}
