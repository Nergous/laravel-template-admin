<?php

namespace Tests\Unit\Services;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\User;
use App\Services\Access\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PermissionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_toggle_rejects_changing_locked_admin_role(): void
    {
        $this->seedRolesAndPermissions();
        $admin = Role::findByName('admin', 'web');

        $this->expectException(ValidationException::class);

        app(PermissionService::class)->toggle($admin, 'users.view', false, User::factory()->create());
    }

    public function test_matrix_lock_follows_configured_superadmin_role(): void
    {
        config(['rbac.superadmin_role' => 'root']);
        $root = Role::create(['name' => 'root', 'guard_name' => 'web']);

        $this->expectException(ValidationException::class);

        app(PermissionService::class)->toggle($root, 'users.view', false, User::factory()->create());
    }

    public function test_toggle_rejects_anti_escalation_for_non_admin_actor(): void
    {
        $this->seedRolesAndPermissions();
        $editor = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $actor = User::factory()->create();

        $this->expectException(ValidationException::class);

        app(PermissionService::class)->toggle($editor, 'users.delete', true, $actor);
    }

    public function test_toggle_to_the_current_state_writes_nothing(): void
    {
        $this->seedRolesAndPermissions();
        $editor = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $editor->givePermissionTo('users.view');
        $admin = $this->actingAsAdmin();
        $logged = fn () => ActivityLog::where('subject_type', $editor->getMorphClass())
            ->where('subject_id', $editor->id)
            ->where('action', 'updated')
            ->count();
        $before = $logged();

        app(PermissionService::class)->toggle($editor->fresh(), 'users.view', true, $admin);
        app(PermissionService::class)->toggle($editor->fresh(), 'users.edit', false, $admin);

        $this->assertSame($before, $logged());
        $this->assertTrue($editor->fresh()->hasPermissionTo('users.view'));
        $this->assertFalse($editor->fresh()->hasPermissionTo('users.edit'));
    }
}
