<?php

namespace Tests\Unit\Services;

use App\Models\Role;
use App\Models\User;
use App\Services\RoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RoleServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_persists_role_with_permissions_and_author(): void
    {
        Permission::findOrCreate('demo.alpha', 'web');
        $actor = User::factory()->create();

        $role = (new RoleService)->create(
            ['name' => 'editor', 'description' => 'Редакторы'],
            ['demo.alpha'],
            $actor,
        );

        $this->assertDatabaseHas('roles', ['name' => 'editor', 'created_by' => $actor->id]);
        $this->assertTrue($role->hasPermissionTo('demo.alpha'));
    }

    public function test_update_rejects_renaming_system_role(): void
    {
        $role = $this->systemRole('admin');

        $this->expectException(ValidationException::class);

        (new RoleService)->update($role, ['name' => 'superadmin', 'description' => null], [], null);
    }

    public function test_delete_rejects_system_role(): void
    {
        $role = $this->systemRole('admin');

        $this->expectException(ValidationException::class);

        (new RoleService)->delete($role);
    }

    /**
     * Создаёт системную роль: is_system защищён от mass-assignment (App\Models\Role),
     * поэтому выставляем флаг явно (как это делает RolePermissionSeeder).
     */
    private function systemRole(string $name): Role
    {
        $role = Role::create(['name' => $name, 'guard_name' => 'web']);
        $role->is_system = true;
        $role->save();

        return $role;
    }

    public function test_delete_rejects_role_assigned_to_users(): void
    {
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        User::factory()->create()->assignRole($role);

        $this->expectException(ValidationException::class);

        (new RoleService)->delete($role);
    }
}
