<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PermissionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_toggle_rejects_changing_locked_admin_role(): void
    {
        $this->seedRolesAndPermissions();
        $admin = Role::findByName('admin', 'web');

        $this->expectException(ValidationException::class);

        (new PermissionService)->toggle($admin->id, 'users.view', false, null);
    }

    public function test_matrix_lock_follows_configured_superadmin_role(): void
    {
        config(['rbac.superadmin_role' => 'root']);
        $root = Role::create(['name' => 'root', 'guard_name' => 'web', 'is_system' => true]);

        $this->expectException(ValidationException::class);

        (new PermissionService)->toggle($root->id, 'users.view', false, null);
    }

    public function test_toggle_rejects_anti_escalation_for_non_admin_actor(): void
    {
        $this->seedRolesAndPermissions();
        $editor = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $actor = User::factory()->create();

        $this->expectException(ValidationException::class);

        (new PermissionService)->toggle($editor->id, 'users.delete', true, $actor);
    }

    public function test_create_auto_grants_new_permission_to_admin_role(): void
    {
        $this->seedRolesAndPermissions();
        $admin = Role::findByName('admin', 'web');

        $permission = (new PermissionService)->create('demo.brand-new');

        $this->assertDatabaseHas('permissions', ['name' => 'demo.brand-new']);
        $this->assertSame('demo.brand-new', $permission->name);
        $this->assertTrue($admin->fresh()->hasPermissionTo('demo.brand-new'));
    }
}
