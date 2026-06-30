<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_persists_user_and_assigns_roles(): void
    {
        Role::findOrCreate('editor', 'web');

        $user = (new UserService)->create([
            'name' => 'Jane',
            'email' => 'jane@example.test',
            'password' => 'Password1',
        ], ['editor']);

        $this->assertDatabaseHas('users', ['email' => 'jane@example.test']);
        $this->assertTrue($user->hasRole('editor'));
        $this->assertTrue(Hash::check('Password1', $user->password));
    }

    public function test_update_keeps_password_when_not_provided(): void
    {
        $user = User::factory()->create();
        $original = $user->password;

        (new UserService)->update($user, [
            'name' => 'Renamed',
            'email' => $user->email,
            'password' => null,
        ], [], null);

        $this->assertSame($original, $user->fresh()->password);
        $this->assertSame('Renamed', $user->fresh()->name);
    }

    public function test_update_rejects_actor_removing_own_admin_role(): void
    {
        $this->seedRolesAndPermissions();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->expectException(ValidationException::class);

        (new UserService)->update($admin, [
            'name' => $admin->name,
            'email' => $admin->email,
            'password' => null,
        ], [], $admin);
    }

    public function test_delete_rejects_self_deletion(): void
    {
        $user = User::factory()->create();

        $this->expectException(ValidationException::class);

        (new UserService)->delete($user, $user);
    }

    public function test_delete_soft_deletes_another_user(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();

        (new UserService)->delete($target, $actor);

        $this->assertSoftDeleted('users', ['id' => $target->id]);
    }
}
