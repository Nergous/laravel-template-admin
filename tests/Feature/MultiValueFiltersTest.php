<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * List filters with several values (?role=admin,operator): the values of one
 * filter combine with OR, different filters with AND. A single value and a
 * query array keep working.
 */
class MultiValueFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_match_any_chosen_role_including_no_role(): void
    {
        $this->actingAsAdmin();
        $operator = User::factory()->create(['name' => 'Operator']);
        $operator->assignRole('operator');
        User::factory()->create(['name' => 'Nobody']);

        // The acting admin plus the operator.
        $this->get(route('admin.users.index', ['role' => 'admin,operator']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('users.data', 2)
                ->where('filters.role', 'admin,operator')
            );

        $this->get(route('admin.users.index', ['role' => 'operator,'.User::WITHOUT_ROLES]))
            ->assertInertia(fn (Assert $page) => $page->has('users.data', 2));

        $this->get(route('admin.users.index', ['role' => User::WITHOUT_ROLES]))
            ->assertInertia(fn (Assert $page) => $page->has('users.data', 1));

        // A query array reads like the comma list.
        $this->get(route('admin.users.index', ['role' => ['admin', 'operator']]))
            ->assertInertia(fn (Assert $page) => $page->has('users.data', 2));
    }

    public function test_bulk_user_status_of_all_matching_takes_several_roles(): void
    {
        $admin = $this->actingAsAdmin();
        $operator = User::factory()->create();
        $operator->assignRole('operator');
        $nobody = User::factory()->create();

        $this->patch(route('admin.users.bulk-status'), ['all' => true, 'active' => false, 'role' => 'operator,missing-role'])
            ->assertSessionHasErrors('role');
        $this->assertTrue($operator->fresh()->is_active);

        $this->patch(route('admin.users.bulk-status'), ['all' => true, 'active' => false, 'role' => 'operator,'.User::WITHOUT_ROLES])
            ->assertSessionHas('success', 'Заблокировано: 2');
        $this->assertFalse($operator->fresh()->is_active);
        $this->assertFalse($nobody->fresh()->is_active);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_activity_log_actions_and_users_combine_with_or_within_and_across_filters(): void
    {
        $this->actingAsUserWith(['activity-log.view']);
        $first = User::factory()->create();
        $second = User::factory()->create();
        $third = User::factory()->create();
        // Own action names: creating the users above logs "created" events too.
        foreach ([[$first, 'test.a'], [$second, 'test.b'], [$first, 'test.c'], [$third, 'test.a']] as [$user, $action]) {
            ActivityLog::create(['user_id' => $user->id, 'action' => $action, 'subject_type' => User::class, 'subject_id' => $user->id]);
        }

        $this->get(route('admin.activity-log.index', ['action' => 'test.a,test.b']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('logs.total', 3)
                ->where('filters.action', 'test.a,test.b')
            );

        $this->get(route('admin.activity-log.index', [
            'action' => 'test.a,test.b',
            'user_id' => $first->id.','.$second->id,
        ]))->assertInertia(fn (Assert $page) => $page
            ->where('logs.total', 2)
            ->where('filters.user_id', $first->id.','.$second->id)
        );
    }

    public function test_activity_log_keeps_the_subject_only_with_one_subject_type(): void
    {
        $this->actingAsUserWith(['activity-log.view']);

        $this->get(route('admin.activity-log.index', ['subject_type' => User::class, 'subject_id' => 5]))
            ->assertInertia(fn (Assert $page) => $page->where('filters.subject_id', '5'));

        $this->get(route('admin.activity-log.index', ['subject_type' => User::class.','.Media::class, 'subject_id' => 5]))
            ->assertInertia(fn (Assert $page) => $page->where('filters.subject_id', null));
    }
}
