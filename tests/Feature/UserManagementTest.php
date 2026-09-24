<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_user(): void
    {
        $this->actingAsAdmin();

        $this->post(route('admin.users.store'), [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'Str0ng!Passw0rd#42',
            'roles' => [],
        ])->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
    }

    public function test_user_store_is_atomic_and_rolls_back_on_failure(): void
    {
        $this->actingAsUserWith(['users.view', 'users.create']);
        Role::findOrCreate('editor', 'web');

        User::created(function () {
            throw new \RuntimeException('boom');
        });

        try {
            $this->post(route('admin.users.store'), [
                'name' => 'Atomic User',
                'email' => 'atomic@example.test',
                'password' => 'Str0ng!Passw0rd#42',
                'roles' => ['editor'],
            ]);
        } catch (\Throwable) {
        }

        $this->assertDatabaseMissing('users', ['email' => 'atomic@example.test']);
    }

    public function test_user_is_soft_deleted_then_restored(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->create();

        $this->delete(route('admin.users.destroy', $target))->assertRedirect();
        $this->assertSoftDeleted('users', ['id' => $target->id]);

        $this->patch(route('admin.users.restore', $target->id))
            ->assertRedirect(route('admin.users.trashed'));
        $this->assertDatabaseHas('users', ['id' => $target->id, 'deleted_at' => null]);
    }

    public function test_user_can_be_force_deleted(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->create();
        $target->delete();

        $this->delete(route('admin.users.force-delete', $target->id))
            ->assertRedirect(route('admin.users.trashed'));
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_admin_can_restore_all_trashed_users_across_pages(): void
    {
        $this->actingAsAdmin();
        $targets = User::factory()->count(12)->create();
        $targets->each->delete();

        $this->post(route('admin.users.bulk-restore'), ['all' => true])
            ->assertRedirect(route('admin.users.trashed'));

        foreach ($targets as $target) {
            $this->assertDatabaseHas('users', [
                'id' => $target->id,
                'deleted_at' => null,
            ]);
        }
    }

    public function test_bulk_force_delete_all_respects_the_current_search_filter(): void
    {
        $this->actingAsAdmin();
        $matched = User::factory()->create([
            'name' => 'Удалить совпадение',
            'email' => 'matched@example.test',
        ]);
        $unmatched = User::factory()->create([
            'name' => 'Оставить пользователя',
            'email' => 'unmatched@example.test',
        ]);
        $matched->delete();
        $unmatched->delete();

        $this->delete(route('admin.users.bulk-force-delete'), [
            'all' => true,
            'search' => 'совпадение',
        ])->assertRedirect(route('admin.users.trashed'));

        $this->assertDatabaseMissing('users', ['id' => $matched->id]);
        $this->assertSoftDeleted('users', ['id' => $unmatched->id]);
    }

    public function test_admin_cannot_delete_themselves(): void
    {
        $admin = $this->actingAsAdmin();

        $this->from(route('admin.users.index'))
            ->delete(route('admin.users.destroy', $admin))
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'deleted_at' => null]);
    }

    public function test_users_index_renders_inertia_page(): void
    {
        Permission::findOrCreate('users.view', 'web');
        $user = User::factory()->create();
        $user->givePermissionTo('users.view');

        $this->actingAs($user)
            ->get('/admin/users')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Users/Index')
                ->has('users.data')
                ->has('roles')
            );
    }

    public function test_user_resource_pages_have_shareable_urls_and_enforce_write_permissions(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->create(['name' => 'Target User']);

        $this->get(route('admin.users.show', $target))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Users/Show')
                ->where('user.id', $target->id)
                ->where('user.name', 'Target User')
            );
        $this->get(route('admin.users.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Users/FormPage')
                ->where('mode', 'create')
                ->has('allRoles')
            );
        $this->get(route('admin.users.edit', $target))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Users/FormPage')
                ->where('mode', 'edit')
                ->where('user.id', $target->id)
            );

        Permission::findOrCreate('users.view', 'web');
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('users.view');
        $this->actingAs($viewer);

        $this->get(route('admin.users.show', $target))->assertOk();
        $this->get(route('admin.users.create'))->assertForbidden();
        $this->get(route('admin.users.edit', $target))->assertForbidden();
    }

    public function test_create_and_update_redirect_to_the_user_page(): void
    {
        $this->actingAsAdmin();

        $response = $this->post(route('admin.users.store'), [
            'name' => 'Shareable User',
            'email' => 'shareable@example.test',
            'password' => 'Str0ng!Passw0rd#42',
            'roles' => [],
        ]);
        $target = User::where('email', 'shareable@example.test')->firstOrFail();
        $response->assertRedirect(route('admin.users.show', $target));

        $this->put(route('admin.users.update', $target), [
            'name' => 'Updated User',
            'email' => $target->email,
            'roles' => [],
        ])->assertRedirect(route('admin.users.show', $target));
    }
}
