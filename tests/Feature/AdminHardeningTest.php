<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Regression tests for the account, audit, permission and media fixes.
 */
class AdminHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_edit_holder_cannot_edit_or_delete_an_admin(): void
    {
        $this->seedRolesAndPermissions();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAsUserWith(['users.view', 'users.edit', 'users.delete']);

        $this->put(route('admin.users.update', $admin), [
            'name' => 'Taken over',
            'email' => 'attacker@example.test',
            'password' => 'Str0ng!Passw0rd#42',
            'roles' => [],
        ])->assertForbidden();

        $this->delete(route('admin.users.destroy', $admin))->assertSessionHasErrors('user');

        $this->assertDatabaseHas('users', ['id' => $admin->id, 'email' => $admin->email, 'deleted_at' => null]);
    }

    public function test_password_and_remember_token_never_reach_the_activity_log(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->create();

        $this->put(route('admin.users.update', $target), [
            'name' => $target->name,
            'email' => $target->email,
            'password' => 'N3w!Str0ngPassw0rd',
            'roles' => [],
        ])->assertRedirect();
        $target->forceFill(['remember_token' => 'secret-token'])->save();

        $logged = ActivityLog::where('subject_id', $target->id)->pluck('changes')->toJson();

        $this->assertStringNotContainsString('N3w!Str0ngPassw0rd', $logged);
        $this->assertStringNotContainsString('secret-token', $logged);
        $this->assertStringNotContainsString('$2y$', $logged);
    }

    public function test_restoring_a_user_with_a_taken_email_is_a_validation_error(): void
    {
        $this->actingAsAdmin();
        $trashed = User::factory()->create(['email' => 'dup@example.test']);
        $trashed->delete();
        User::factory()->create(['email' => 'dup@example.test']);

        $this->patch(route('admin.users.restore', $trashed->id))->assertSessionHasErrors('user');

        $this->assertSoftDeleted('users', ['id' => $trashed->id]);
    }

    public function test_blocked_user_cannot_log_in_and_is_signed_out(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertDatabaseHas('activity_log', ['action' => 'login_failed', 'subject_id' => $user->id]);

        $this->actingAs($user)->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_login_records_time_and_forced_password_change_redirects_to_profile(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('admin.profile.show'));

        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('activity_log', ['action' => 'login', 'subject_id' => $user->id]);
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.profile.show'));
        $this->get(route('admin.profile.show'))->assertOk();
    }

    public function test_search_links_users_to_their_page_and_finds_media_by_display_name(): void
    {
        $admin = $this->actingAsAdmin();
        Media::create(['filename' => 'media/abc123.webp', 'original_name' => 'Квартальный отчёт.pdf', 'type' => 'document']);

        $this->getJson(route('admin.search', ['q' => $admin->email]))
            ->assertOk()
            ->assertJsonPath('results.0.url', route('admin.users.show', $admin));

        $this->getJson(route('admin.search', ['q' => 'Квартальный']))
            ->assertOk()
            ->assertJsonFragment(['type' => 'media', 'label' => 'Квартальный отчёт.pdf']);
    }

    public function test_system_permission_cannot_be_deleted_but_custom_one_can(): void
    {
        $this->actingAsAdmin();
        $system = Permission::findByName('users.view', 'web');
        $custom = Permission::create(['name' => 'reports.view', 'guard_name' => 'web']);

        $this->delete(route('admin.permissions.destroy', $system))->assertSessionHasErrors('permission');
        $this->delete(route('admin.permissions.destroy', $custom))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('permissions', ['name' => 'users.view']);
        $this->assertDatabaseMissing('permissions', ['name' => 'reports.view']);
    }

    public function test_matrix_bulk_toggle_grants_a_row_to_manageable_roles_only(): void
    {
        $this->actingAsAdmin();
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $operator = Role::findByName('operator', 'web');

        $this->patch(route('admin.permissions.sync-many'), [
            'role_ids' => [$role->id],
            'permissions' => ['users.view', 'roles.view'],
            'granted' => true,
        ])->assertSessionHasNoErrors();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertTrue($role->fresh()->hasPermissionTo('users.view'));
        $this->assertTrue($role->fresh()->hasPermissionTo('roles.view'));
        $this->assertFalse($operator->fresh()->hasPermissionTo('users.view'));
    }

    public function test_clearing_the_log_rejects_future_dates_and_leaves_a_trace(): void
    {
        $this->actingAsAdmin();

        $this->delete(route('admin.activity-log.clear'), ['before' => now()->addDays(2)->toDateString()])
            ->assertSessionHasErrors('before');

        $this->delete(route('admin.activity-log.clear'), ['before' => now()->toDateString()])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('activity_log', ['action' => 'cleared']);
    }

    public function test_activity_log_export_is_csv_and_neutralizes_formulas(): void
    {
        $this->actingAsAdmin();
        ActivityLog::record(null, 'cleared', null, '=HYPERLINK("x")');

        $response = $this->get(route('admin.activity-log.export'));

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString("'=HYPERLINK", $response->streamedContent());
    }

    public function test_media_poll_returns_only_the_current_users_uploads(): void
    {
        $me = $this->actingAsUserWith(['media.view']);
        $other = User::factory()->create();

        $mine = Media::create(['filename' => 'media/mine.webp', 'original_name' => 'mine.webp']);
        Media::create(['filename' => 'media/theirs.webp', 'original_name' => 'theirs.webp'])
            ->forceFill(['created_by' => $other->id])->saveQuietly();

        $this->getJson(route('admin.media.poll', ['after_id' => 0]))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $mine->id);

        $this->assertSame($me->id, $mine->fresh()->created_by);
    }

    public function test_backups_are_gated_by_their_permissions(): void
    {
        $this->actingAsUserWith([]);
        $this->get(route('admin.backups.index'))->assertForbidden();
        $this->post(route('admin.backups.store'))->assertForbidden();

        Auth::logout();
        $this->actingAsUserWith(['backups.view']);
        $this->get(route('admin.backups.index'))->assertOk();
        $this->post(route('admin.backups.store'))->assertForbidden();
    }

    public function test_settings_reject_zero_limits(): void
    {
        $this->actingAsAdmin();

        $this->put(route('admin.settings.update'), [
            'settings' => [
                'security' => ['session_lifetime' => 0, 'login_throttle' => 0],
            ],
        ])->assertSessionHasErrors(['settings.security.session_lifetime', 'settings.security.login_throttle']);
    }

    public function test_public_layout_renders_meta_tags_from_seo_settings(): void
    {
        Setting::setMany([
            'general' => ['app_name' => 'Acme'],
            'seo' => [
                'meta_description' => 'Описание сайта',
                'canonical_domain' => 'https://acme.test',
                'og_image' => '/storage/media/cover.webp',
                'indexable' => false,
            ],
        ]);
        Setting::flushCache();

        $html = view('public')->render();

        $this->assertStringContainsString('<title>Acme</title>', $html);
        $this->assertStringContainsString('content="Описание сайта"', $html);
        $this->assertStringContainsString('content="noindex, nofollow"', $html);
        $this->assertStringContainsString('content="https://acme.test/storage/media/cover.webp"', $html);
        $this->assertStringContainsString('rel="canonical" href="https://acme.test/"', $html);
    }
}
