<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ListSortingTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_index_exposes_validated_default_sort(): void
    {
        $this->actingAsAdmin();

        $this->get('/admin/users')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Users/Index')
                ->where('currentSort', 'id')
                ->where('currentDirection', 'desc')
            );
    }

    public function test_users_index_rejects_invalid_sort_and_keeps_display_in_sync(): void
    {
        $this->actingAsAdmin();

        $this->get('/admin/users?sort=bogus&direction=garbage')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Users/Index')
                ->where('currentSort', 'id')
                ->where('currentDirection', 'desc')
            );
    }

    public function test_users_index_honors_valid_sort_query(): void
    {
        $this->actingAsAdmin();

        $this->get('/admin/users?sort=name&direction=asc')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Users/Index')
                ->where('currentSort', 'name')
                ->where('currentDirection', 'asc')
            );
    }

    public function test_users_trashed_exposes_validated_sort(): void
    {
        $this->actingAsAdmin();

        $this->get('/admin/users/trashed?direction=garbage')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Users/Trashed')
                ->where('currentSort', 'id')
                ->where('currentDirection', 'desc')
            );
    }

    public function test_media_index_exposes_validated_default_sort(): void
    {
        $this->actingAsAdmin();

        $this->get('/admin/media')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Media/Index')
                ->where('currentSort', 'created_at')
                ->where('currentDirection', 'desc')
            );
    }

    public function test_media_index_rejects_invalid_sort_column(): void
    {
        $this->actingAsAdmin();

        $this->get('/admin/media?sort=bogus')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Media/Index')
                ->where('currentSort', 'created_at')
                ->where('currentDirection', 'desc')
            );
    }
}
