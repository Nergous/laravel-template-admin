<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_renders_inertia_page(): void
    {
        Permission::findOrCreate('activity-log.view', 'web');
        $user = User::factory()->create();
        $user->givePermissionTo('activity-log.view');

        $this->actingAs($user)
            ->get('/admin/activity-log')
            ->assertInertia(fn (Assert $page) => $page
                ->component('ActivityLog/Index')
                ->has('logs.data')
            );
    }

    public function test_recent_feed_forbidden_without_activity_log_view(): void
    {
        $this->actingAsUserWith([]); // аутентифицирован, но без activity-log.view

        $this->getJson(route('admin.notifications.recent'))->assertForbidden();
    }

    public function test_recent_feed_accessible_with_activity_log_view(): void
    {
        $this->actingAsUserWith(['activity-log.view']);

        $this->getJson(route('admin.notifications.recent'))
            ->assertOk()
            ->assertJsonStructure(['count', 'items']);
    }

    public function test_clear_forbidden_without_delete_permission(): void
    {
        // Право на просмотр есть, на очистку — нет: гранулярность view ≠ delete.
        $this->actingAsUserWith(['activity-log.view']);

        $log = ActivityLog::create([
            'action' => 'created',
            'subject_type' => User::class,
            'subject_id' => 1,
            'created_at' => now()->subDays(10),
        ]);

        $this->delete(route('admin.activity-log.clear'), ['before' => now()->toDateString()])
            ->assertForbidden();

        // Запрос отклонён — старая запись на месте.
        $this->assertDatabaseHas('activity_log', ['id' => $log->id]);
    }

    public function test_clear_requires_a_date(): void
    {
        $this->actingAsAdmin();

        $this->from(route('admin.activity-log.index'))
            ->delete(route('admin.activity-log.clear'), [])
            ->assertSessionHasErrors('before');
    }

    public function test_clear_deletes_events_before_date_keeps_newer(): void
    {
        $this->actingAsAdmin();

        $old = ActivityLog::create([
            'action' => 'created',
            'subject_type' => User::class,
            'subject_id' => 1,
            'created_at' => now()->subDays(10),
        ]);
        $recent = ActivityLog::create([
            'action' => 'updated',
            'subject_type' => User::class,
            'subject_id' => 1,
            'created_at' => now()->subDay(),
        ]);

        $this->from(route('admin.activity-log.index'))
            ->delete(route('admin.activity-log.clear'), [
                'before' => now()->subDays(5)->toDateString(),
            ])
            ->assertRedirect(route('admin.activity-log.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('activity_log', ['id' => $old->id]);
        $this->assertDatabaseHas('activity_log', ['id' => $recent->id]);
    }
}
