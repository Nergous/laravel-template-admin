<?php

namespace Tests\Feature;

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
}
