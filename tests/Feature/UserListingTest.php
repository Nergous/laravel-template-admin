<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The users list and what hangs off it: filters, sorting, bulk status,
 * CSV export, the trash, and the user page.
 */
class UserListingTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_query_count_does_not_grow_with_the_number_of_rows(): void
    {
        $this->seedRolesAndPermissions();
        $this->actingAsUserWith(['users.view', 'users.edit']);
        $role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
        $role->givePermissionTo('users.view');

        $this->createUsersWithRole($role, 2);
        $this->get(route('admin.users.index'))->assertOk();
        $few = $this->countQueries(fn () => $this->get(route('admin.users.index'))->assertOk());

        $this->createUsersWithRole($role, 7);
        $many = $this->countQueries(fn () => $this->get(route('admin.users.index'))->assertOk());

        $this->assertSame($few, $many);
    }

    public function test_index_hides_permission_relations_and_flags_manageable_rows(): void
    {
        $this->seedRolesAndPermissions();
        $this->actingAsUserWith(['users.view', 'users.edit']);
        User::factory()->create()->assignRole('admin');

        $this->get(route('admin.users.index', ['sort' => 'id', 'direction' => 'asc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('users.data.0.can_manage', true)
                ->where('users.data.1.can_manage', false)
                ->missing('users.data.1.permissions')
                ->missing('users.data.1.roles.0.permissions')
            );
    }

    public function test_index_filters_by_status_and_pending_password_change(): void
    {
        $this->actingAsAdmin();
        $blocked = User::factory()->create(['name' => 'Blocked', 'is_active' => false]);
        $pending = User::factory()->create(['name' => 'Pending', 'must_change_password' => true]);

        $this->get(route('admin.users.index', ['status' => 'blocked']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.id', $blocked->id)
                ->where('filters.status', 'blocked')
            );

        $this->get(route('admin.users.index', ['must_change_password' => 1]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.id', $pending->id)
            );

        $this->get(route('admin.users.index', ['status' => 'active']))
            ->assertInertia(fn (Assert $page) => $page->has('users.data', 2));
    }

    public function test_index_sorts_by_last_login_with_an_id_tiebreaker(): void
    {
        $admin = $this->actingAsAdmin();
        $admin->updateSilently(['last_login_at' => now()->subDays(3)]);
        $first = User::factory()->create(['last_login_at' => now()->subDay()]);
        $second = User::factory()->create(['last_login_at' => now()->subDay()]);

        $this->get(route('admin.users.index', ['sort' => 'last_login_at', 'direction' => 'desc']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentSort', 'last_login_at')
                ->where('users.data.0.id', $first->id)
                ->where('users.data.1.id', $second->id)
                ->where('users.data.2.id', $admin->id)
            );
    }

    public function test_bulk_block_skips_self_and_unmanageable_users_and_logs_each_change(): void
    {
        $this->seedRolesAndPermissions();
        $actor = $this->actingAsUserWith(['users.view', 'users.edit']);
        $target = User::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->patch(route('admin.users.bulk-status'), [
            'ids' => [$actor->id, $target->id, $admin->id],
            'active' => false,
        ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success', 'Заблокировано: 1. Пропущено: 2 (нет прав или собственная учётная запись)');

        $this->assertFalse($target->fresh()->is_active);
        $this->assertTrue($actor->fresh()->is_active);
        $this->assertTrue($admin->fresh()->is_active);

        $log = ActivityLog::where('action', 'updated')
            ->where('subject_type', User::class)
            ->where('subject_id', $target->id)
            ->sole();
        $this->assertSame([true, false], $log->changes['is_active']);
        $this->assertSame($actor->id, $log->user_id);
    }

    public function test_bulk_unblock_of_all_matching_users_respects_the_filters(): void
    {
        $admin = $this->actingAsAdmin();
        $matched = User::factory()->count(3)->create(['name' => 'Locked out', 'is_active' => false]);
        $otherName = User::factory()->create(['name' => 'Someone else', 'is_active' => false]);

        $this->patch(route('admin.users.bulk-status'), [
            'all' => true,
            'active' => true,
            'search' => 'Locked',
            'status' => 'blocked',
        ])->assertSessionHas('success', 'Разблокировано: 3');

        foreach ($matched as $user) {
            $this->assertTrue($user->fresh()->is_active);
        }
        $this->assertFalse($otherName->fresh()->is_active);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_bulk_delete_of_all_matching_users_respects_the_password_change_filter(): void
    {
        $admin = $this->actingAsAdmin();
        $pending = User::factory()->create(['must_change_password' => true]);
        $other = User::factory()->create();

        $this->delete(route('admin.users.bulk-destroy'), [
            'all' => true,
            'must_change_password' => true,
        ])->assertSessionHas('success', 'Перемещено в корзину: 1');

        $this->assertSoftDeleted('users', ['id' => $pending->id]);
        $this->assertNotSoftDeleted('users', ['id' => $other->id]);
        $this->assertNotSoftDeleted('users', ['id' => $admin->id]);
    }

    public function test_bulk_delete_counts_the_own_account_as_skipped(): void
    {
        $admin = $this->actingAsAdmin();
        $target = User::factory()->create();

        $this->delete(route('admin.users.bulk-destroy'), ['ids' => [$admin->id, $target->id]])
            ->assertSessionHas('success', 'Перемещено в корзину: 1. Пропущено: 1 (нет прав или собственная учётная запись)');
    }

    public function test_bulk_status_requires_the_edit_permission(): void
    {
        $this->actingAsUserWith(['users.view']);
        $target = User::factory()->create();

        $this->patch(route('admin.users.bulk-status'), ['ids' => [$target->id], 'active' => false])
            ->assertForbidden();

        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_trashed_rows_report_whether_the_actor_can_manage_them(): void
    {
        $this->seedRolesAndPermissions();
        $this->actingAsUserWith(['users.view', 'users.delete']);
        $plain = User::factory()->create();
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $plain->delete();
        $admin->delete();

        $this->get(route('admin.users.trashed', ['sort' => 'id', 'direction' => 'asc']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Users/Trashed')
                ->where('users.data.0.id', $plain->id)
                ->where('users.data.0.can_manage', true)
                ->where('users.data.1.id', $admin->id)
                ->where('users.data.1.can_manage', false)
                ->missing('users.data.1.roles.0.permissions')
            );
    }

    public function test_show_lists_recent_activity_by_and_about_the_user(): void
    {
        $admin = $this->actingAsAdmin();
        $target = User::factory()->create();
        $unrelated = User::factory()->create();

        ActivityLog::actingAs($target, fn () => ActivityLog::record($unrelated, 'updated'));
        ActivityLog::actingAs($unrelated, fn () => ActivityLog::record(null, 'login'));

        $this->get(route('admin.users.show', $target))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Users/Show')
                ->where('canImpersonate', true)
                // The factory "created" entry for the target plus its own action.
                ->has('activity', 2)
                ->where('activity.0.action', 'updated')
                ->where('activity.1.action', 'created')
                ->where('activityLinks.byUser', route('admin.activity-log.index', ['user_id' => $target->id]))
                ->where('activityLinks.aboutUser', route('admin.activity-log.index', [
                    'subject_type' => User::class,
                    'subject_id' => $target->id,
                ]))
                ->missing('user.permissions')
            );

        $this->get(route('admin.users.show', $admin))
            ->assertInertia(fn (Assert $page) => $page->where('canImpersonate', false));
    }

    public function test_show_hides_activity_without_the_activity_log_permission(): void
    {
        $this->actingAsUserWith(['users.view']);
        $target = User::factory()->create();

        $this->get(route('admin.users.show', $target))
            ->assertInertia(fn (Assert $page) => $page
                ->where('activity', [])
                ->where('activityLinks', null)
                ->where('canImpersonate', false)
            );
    }

    public function test_export_streams_filtered_users_as_safe_csv_and_logs_it(): void
    {
        $admin = $this->actingAsAdmin();
        User::factory()->create(['name' => '=HYPERLINK(1)', 'is_active' => false]);
        User::factory()->create(['name' => 'Active one']);

        $response = $this->get(route('admin.users.export', ['status' => 'blocked']));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $csv = $response->streamedContent();
        $lines = array_values(array_filter(explode("\n", $csv)));

        $this->assertStringStartsWith("\xEF\xBB\xBFID;", $csv);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString(";'=HYPERLINK(1);", $lines[1]);
        $this->assertStringContainsString('Заблокирован', $lines[1]);
        $this->assertStringNotContainsString('Active one', $csv);

        $log = ActivityLog::where('action', 'users_exported')->sole();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame([null, 1], $log->changes['rows']);
    }

    public function test_export_requires_the_view_permission(): void
    {
        $this->actingAsUserWith([]);

        $this->get(route('admin.users.export'))->assertForbidden();
    }

    public function test_search_treats_like_wildcards_literally(): void
    {
        $this->actingAsUserWith(['users.view']);
        $exact = User::factory()->create(['email' => 'a_b@example.test']);
        User::factory()->create(['email' => 'axb@example.test']);
        $percent = User::factory()->create(['name' => '100% done']);
        User::factory()->create(['name' => '1000 done']);

        $this->get(route('admin.users.index', ['search' => 'a_b@']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.id', $exact->id));
        $this->get(route('admin.users.index', ['search' => '100%']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('users.data', 1)
                ->where('users.data.0.id', $percent->id));
    }

    public function test_deleting_from_a_filtered_list_returns_to_the_same_view(): void
    {
        $this->actingAsAdmin();
        $target = User::factory()->create(['name' => 'Target']);
        $list = route('admin.users.index', ['search' => 'Tar', 'sort' => 'name']);

        $this->from($list)->delete(route('admin.users.destroy', $target))->assertRedirect($list);

        // From the user page (the record is gone) the plain list is used.
        $other = User::factory()->create();
        $this->from(route('admin.users.show', $other))
            ->delete(route('admin.users.destroy', $other))
            ->assertRedirect(route('admin.users.index'));
    }

    public function test_a_page_past_the_end_redirects_to_the_last_page(): void
    {
        $this->actingAsUserWith(['users.view']);

        $this->get(route('admin.users.index', ['page' => 50, 'search' => 'x']))
            ->assertRedirect(route('admin.users.index', ['page' => 1, 'search' => 'x']));
    }

    public function test_trash_routes_reject_non_numeric_ids(): void
    {
        $this->actingAsAdmin();

        $this->patch('/admin/users/restore/abc')->assertNotFound();
        $this->delete('/admin/users/force/abc')->assertNotFound();
    }

    private function createUsersWithRole(Role $role, int $count): void
    {
        User::factory()->count($count)->create()->each(function (User $user) use ($role) {
            $user->assignRole($role);
            $user->givePermissionTo(Permission::findByName('users.view', 'web'));
        });
    }

    private function countQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });

        $callback();

        return $count;
    }
}
