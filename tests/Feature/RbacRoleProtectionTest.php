<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Services\RoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The superadmin cannot be locked out, and nobody can strip a role that holds
 * permissions they do not have themselves.
 */
class RbacRoleProtectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_role_permissions_cannot_be_changed_through_the_role_form(): void
    {
        $this->actingAsAdmin();
        $admin = Role::findByName('admin', 'web');
        $before = $this->permissionsOf($admin);

        $this->put(route('admin.roles.update', $admin), [
            'name' => 'admin',
            'permissions' => array_values(array_diff($before, ['roles.edit', 'permissions.edit'])),
        ])->assertSessionHasErrors('permissions');

        $this->assertSame($before, $this->permissionsOf($admin));
    }

    public function test_superadmin_keeps_access_when_permission_rows_are_missing(): void
    {
        $this->actingAsAdmin();
        Role::findByName('admin', 'web')->revokePermissionTo(['roles.view', 'users.view']);

        $this->get(route('admin.users.index'))->assertOk();
        $this->get(route('admin.roles.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.can', fn ($can) => collect($can)->contains('roles.view')));
    }

    public function test_role_update_without_permissions_key_keeps_the_permissions(): void
    {
        $this->actingAsAdmin();
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $role->givePermissionTo('users.view');

        $this->put(route('admin.roles.update', $role), ['name' => 'editor', 'description' => 'Редакторы'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['users.view'], $this->permissionsOf($role));
    }

    public function test_matrix_editor_cannot_strip_a_role_holding_permissions_they_lack(): void
    {
        $this->seedRolesAndPermissions();
        $chief = Role::create(['name' => 'chief', 'guard_name' => 'web']);
        $chief->givePermissionTo(['users.view', 'users.edit', 'backups.download']);
        $this->actingAsUserWith(['permissions.view', 'permissions.edit', 'users.view', 'users.edit']);

        $this->patch(route('admin.permissions.sync'), [
            'role_id' => $chief->id,
            'permission' => 'users.edit',
            'granted' => false,
        ])->assertSessionHasErrors('matrix');

        $this->patch(route('admin.permissions.sync-many'), [
            'role_ids' => [$chief->id],
            'permissions' => ['users.view', 'users.edit'],
            'granted' => false,
        ])->assertSessionHasErrors('matrix');

        $this->assertSame(['backups.download', 'users.edit', 'users.view'], $this->permissionsOf($chief));
    }

    public function test_role_editor_cannot_change_a_role_holding_permissions_they_lack(): void
    {
        $this->seedRolesAndPermissions();
        $chief = Role::create(['name' => 'chief', 'guard_name' => 'web']);
        $chief->givePermissionTo(['users.view', 'backups.download']);
        $actor = $this->actingAsUserWith(['roles.view', 'roles.edit', 'users.view']);

        $this->get(route('admin.roles.edit', $chief))->assertForbidden();
        $this->put(route('admin.roles.update', $chief), ['name' => 'chief', 'permissions' => ['users.view']])
            ->assertForbidden();
        $this->assertSame(['backups.download', 'users.view'], $this->permissionsOf($chief));

        // The service enforces the rule for any caller, not only the HTTP layer.
        $this->expectException(ValidationException::class);
        app(RoleService::class)->update($chief, ['name' => 'chief'], ['users.view'], $actor);
    }

    public function test_role_within_the_actors_permissions_stays_editable(): void
    {
        $this->seedRolesAndPermissions();
        $helper = Role::create(['name' => 'helper', 'guard_name' => 'web']);
        $helper->givePermissionTo(['users.view', 'media.view']);
        $this->actingAsUserWith(['permissions.view', 'permissions.edit', 'users.view', 'media.view']);

        $this->patch(route('admin.permissions.sync'), [
            'role_id' => $helper->id,
            'permission' => 'media.view',
            'granted' => false,
        ])->assertSessionHasNoErrors();

        $this->assertSame(['users.view'], $this->permissionsOf($helper));
    }

    /** @return list<string> */
    private function permissionsOf(Role $role): array
    {
        return $role->fresh()->permissions()->pluck('name')->sort()->values()->all();
    }
}
