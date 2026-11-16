<?php

namespace Tests\Feature;

use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SidebarCountsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Count badges must not reveal the numbers for sections the user has no
     * *.view permission for (a leak of data structure/volume).
     */
    public function test_counts_are_gated_by_view_permission(): void
    {
        $this->actingAsUserWith(['users.view']); // view users only

        $this->get(route('admin.users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.users', fn ($v) => $v !== null)
                ->where('counts.roles', null)
                ->where('counts.permissions', null)
                ->where('counts.media', null));
    }

    public function test_admin_sees_all_counts(): void
    {
        $this->actingAsAdmin();

        $this->get(route('admin.users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.users', fn ($v) => $v !== null)
                ->where('counts.roles', fn ($v) => $v !== null)
                ->missing('counts.recentActivity'));
    }

    public function test_media_count_is_visible_only_with_media_view_permission(): void
    {
        Media::factory()->create();
        $this->actingAsUserWith(['media.view']);

        $this->get(route('admin.media.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.media', 1)
                ->where('counts.users', null));
    }
}
