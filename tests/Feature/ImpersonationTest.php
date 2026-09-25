<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ImpersonationTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_signs_in_as_a_user_and_returns(): void
    {
        $admin = $this->actingAsAdmin();
        $target = User::factory()->create(['name' => 'Target']);
        $target->assignRole('operator');

        $this->post(route('admin.users.impersonate', $target))
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($target);
        $this->assertSame($admin->id, session(Impersonation::SESSION_KEY));

        // The operator role has no users.view: the panel really shows the user's access.
        $this->get(route('admin.users.index'))->assertForbidden();
        $this->get(route('admin.media.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.id', $target->id)
                ->where('auth.impersonator.id', $admin->id));

        $this->post(route('admin.impersonation.stop'))
            ->assertRedirect(route('admin.users.show', $target));

        $this->assertAuthenticatedAs($admin);
        $this->assertNull(session(Impersonation::SESSION_KEY));
        $this->assertSame(
            ['impersonation_started', 'impersonation_stopped'],
            ActivityLog::whereIn('action', ['impersonation_started', 'impersonation_stopped'])->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_actions_in_the_mode_record_the_superadmin(): void
    {
        $admin = $this->actingAsAdmin();
        $target = User::factory()->create(['name' => 'Target']);
        $target->assignRole('operator');
        $this->post(route('admin.users.impersonate', $target));

        ActivityLog::record($target, 'updated');

        $log = ActivityLog::where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame($target->id, $log->user_id);
        $this->assertSame($admin->id, $log->impersonator_id);
        $this->assertStringContainsString($admin->name, $log->actorName());
    }

    public function test_only_superadmin_can_impersonate_and_never_another_superadmin(): void
    {
        $this->seedRolesAndPermissions();
        $target = User::factory()->create();

        $this->actingAsUserWith(['users.view', 'users.edit']);
        $this->post(route('admin.users.impersonate', $target))->assertForbidden();

        $this->actingAsAdmin();
        $peer = User::factory()->create();
        $peer->assignRole('admin');
        $this->post(route('admin.users.impersonate', $peer))->assertForbidden();

        $blocked = User::factory()->create(['is_active' => false]);
        $this->post(route('admin.users.impersonate', $blocked))->assertForbidden();
    }

    public function test_nested_impersonation_is_refused(): void
    {
        $this->actingAsAdmin();
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->post(route('admin.users.impersonate', $first));
        $this->post(route('admin.users.impersonate', $second))->assertForbidden();
        $this->assertAuthenticatedAs($first);
    }

    public function test_forced_password_change_does_not_trap_the_superadmin(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->create(['must_change_password' => true]);

        $this->post(route('admin.users.impersonate', $target));

        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_return_signs_out_when_the_superadmin_lost_access(): void
    {
        $admin = $this->actingAsAdmin();
        $target = User::factory()->create();
        $this->post(route('admin.users.impersonate', $target));

        $admin->removeRole('admin');

        $this->post(route('admin.impersonation.stop'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_stop_without_the_mode_is_not_found(): void
    {
        $this->actingAsAdmin();

        $this->post(route('admin.impersonation.stop'))->assertNotFound();
    }
}
