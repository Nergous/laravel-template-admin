<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
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
            'password' => 'Password1',
            'roles' => [],
        ])->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
    }

    public function test_user_store_is_atomic_and_rolls_back_on_failure(): void
    {
        $this->actingAsUserWith(['users.view', 'users.create']);
        \Spatie\Permission\Models\Role::findOrCreate('editor', 'web');

        User::created(function () {
            throw new \RuntimeException('boom');
        });

        try {
            $this->post(route('admin.users.store'), [
                'name' => 'Atomic User',
                'email' => 'atomic@example.test',
                'password' => 'Password1',
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
        \Spatie\Permission\Models\Permission::findOrCreate('users.view', 'web');
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
}
