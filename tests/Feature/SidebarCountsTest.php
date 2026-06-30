<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SidebarCountsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Счётчики-бейджи не должны раскрывать числа разделов, на которые у
     * пользователя нет права *.view (утечка структуры/объёма данных).
     */
    public function test_counts_are_gated_by_view_permission(): void
    {
        $this->actingAsUserWith(['users.view']); // только просмотр пользователей

        $this->get(route('admin.users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.users', fn ($v) => $v !== null)
                ->where('counts.roles', null)
                ->where('counts.permissions', null)
                ->where('counts.media', null)
                ->where('counts.recentActivity', null));
    }

    public function test_admin_sees_all_counts(): void
    {
        $this->actingAsAdmin();

        $this->get(route('admin.users.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('counts.users', fn ($v) => $v !== null)
                ->where('counts.recentActivity', fn ($v) => $v !== null));
    }
}
