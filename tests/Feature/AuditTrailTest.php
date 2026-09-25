<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_restoring_a_user_is_logged_once(): void
    {
        $this->actingAsAdmin();
        $user = User::factory()->create();
        $user->delete();
        ActivityLog::query()->delete();

        $this->patch(route('admin.users.restore', $user->id))->assertRedirect();

        $this->assertSame(['restored'], ActivityLog::pluck('action')->all());
    }

    public function test_logout_is_logged_but_stays_out_of_the_bell(): void
    {
        $user = $this->actingAsAdmin();
        $other = User::factory()->create();

        $this->post(route('logout'))->assertRedirect(route('login'));

        $log = ActivityLog::where('action', 'logout')->firstOrFail();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame(0, ActivityLog::forBell($other)->where('action', 'logout')->count());
    }

    public function test_settings_changes_are_logged_with_a_diff(): void
    {
        $this->actingAsAdmin();
        $payload = ['settings' => Setting::grouped()];
        $payload['settings']['security']['login_throttle'] = 9;

        $this->put(route('admin.settings.update'), $payload)->assertRedirect();

        $log = ActivityLog::where('action', 'settings_updated')->firstOrFail();
        $this->assertSame(['security.login_throttle' => [5, 9]], $log->changes);

        // Saving the same values again leaves no empty entry.
        $this->put(route('admin.settings.update'), $payload)->assertRedirect();
        $this->assertSame(1, ActivityLog::where('action', 'settings_updated')->count());
    }

    public function test_changing_email_in_profile_requires_the_current_password(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        $this->actingAs($user);

        $this->put(route('admin.profile.update'), ['name' => 'New name', 'email' => 'new@example.com'])
            ->assertSessionHasErrors('current_password');

        $this->put(route('admin.profile.update'), [
            'name' => 'New name', 'email' => 'new@example.com', 'current_password' => 'wrong',
        ])->assertSessionHasErrors('current_password');

        $this->put(route('admin.profile.update'), [
            'name' => 'New name', 'email' => 'new@example.com', 'current_password' => 'password',
        ])->assertSessionHasNoErrors();
        $this->assertSame('new@example.com', $user->fresh()->email);

        // The name alone changes without a password.
        $this->put(route('admin.profile.update'), ['name' => 'Other', 'email' => 'new@example.com'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Other', $user->fresh()->name);
    }

    public function test_signing_out_other_devices_is_logged(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->post(route('admin.profile.sessions.logout-others'), ['password' => 'password'])
            ->assertSessionHasNoErrors();

        $this->assertTrue(ActivityLog::where('action', 'sessions_ended')->where('user_id', $user->id)->exists());
    }

    public function test_export_does_not_query_per_row(): void
    {
        $admin = $this->actingAsAdmin();
        $count = function (int $rows) use ($admin): int {
            foreach (User::factory()->count($rows)->create() as $author) {
                $this->actingAs($author);
                ActivityLog::record($author, 'updated');
            }
            $this->actingAs($admin);

            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->get(route('admin.activity-log.export'))->streamedContent();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        // Warm-up: permission and settings caches are filled on the first request.
        $count(1);

        $this->assertSame($count(3), $count(15));
    }

    public function test_log_filters_by_one_entity(): void
    {
        $this->actingAsAdmin();
        $first = User::factory()->create(['name' => 'First']);
        $second = User::factory()->create(['name' => 'Second']);

        $this->get(route('admin.activity-log.index', ['subject_type' => User::class, 'subject_id' => $first->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('subjectLabel', 'First')
                ->where('logs.data', fn ($logs) => collect($logs)->every(fn ($log) => $log['subject'] === 'First')
                    && collect($logs)->isNotEmpty()));
    }

    public function test_bell_counter_endpoint(): void
    {
        $viewer = $this->actingAsUserWith(['activity-log.view']);
        $other = User::factory()->create();
        $before = $this->getJson(route('admin.notifications.count'))->json('count');

        $this->actingAs($other);
        ActivityLog::record($other, 'updated');
        $this->actingAs($viewer);

        $this->getJson(route('admin.notifications.count'))->assertOk()->assertJson(['count' => $before + 1]);
    }

    public function test_role_deletion_is_logged_after_the_delete(): void
    {
        $this->actingAsAdmin();
        $role = Role::create(['name' => 'temp', 'guard_name' => 'web']);

        $this->delete(route('admin.roles.destroy', $role))->assertRedirect();

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
        $this->assertTrue(ActivityLog::where('action', 'deleted')->where('subject_label', 'temp')->exists());
    }
}
