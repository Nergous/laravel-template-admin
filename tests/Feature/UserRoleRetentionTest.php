<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Saving a user keeps roles the form did not mean to change. */
class UserRoleRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_with_users_edit_can_save_themselves_keeping_the_system_role(): void
    {
        $this->seedRolesAndPermissions();
        $operator = User::factory()->create();
        $operator->assignRole('operator');
        $operator->givePermissionTo(['users.view', 'users.edit']);
        $this->actingAs($operator);

        $this->put(route('admin.users.update', $operator), [
            'name' => 'Новое имя',
            'email' => $operator->email,
            'roles' => ['operator'],
        ])->assertSessionHasNoErrors();

        $this->assertSame('Новое имя', $operator->fresh()->name);
        $this->assertTrue($operator->fresh()->hasRole('operator'));

        // Keeping a role is fine; gaining a system role still is not.
        $this->put(route('admin.users.update', $operator), [
            'name' => 'Новое имя',
            'email' => $operator->email,
            'roles' => ['operator', 'admin'],
        ])->assertSessionHasErrors('roles.1');

        $this->assertFalse($operator->fresh()->hasRole('admin'));
    }

    public function test_user_update_without_roles_key_keeps_the_roles(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->create();
        $target->assignRole('operator');

        $this->put(route('admin.users.update', $target), [
            'name' => 'Без ролей в запросе',
            'email' => $target->email,
        ])->assertSessionHasNoErrors();
        $this->assertTrue($target->fresh()->hasRole('operator'));

        $this->put(route('admin.users.update', $target), [
            'name' => 'Роли сняты явно',
            'email' => $target->email,
            'roles' => [],
        ])->assertSessionHasNoErrors();
        $this->assertFalse($target->fresh()->hasRole('operator'));
    }
}
