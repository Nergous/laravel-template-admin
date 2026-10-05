<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Role;
use App\Models\User;
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

    public function test_media_index_sorts_by_size_for_the_file_list(): void
    {
        $this->actingAsAdmin();
        Media::create(['filename' => 'media/small.txt', 'original_name' => 'small.txt', 'size' => 10]);
        Media::create(['filename' => 'media/big.txt', 'original_name' => 'big.txt', 'size' => 5000]);

        $this->get('/admin/media?sort=size&direction=desc')
            ->assertInertia(fn (Assert $page) => $page
                ->where('currentSort', 'size')
                ->where('media.data.0.original_name', 'big.txt')
                ->where('media.data.1.original_name', 'small.txt')
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

    public function test_users_index_honors_allowed_page_size(): void
    {
        $this->actingAsAdmin();
        User::factory()->count(30)->create();

        $this->get('/admin/users?per_page=25')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Users/Index')
                ->where('perPage', 25)
                ->where('users.per_page', 25)
                ->has('users.data', 25)
            );
    }

    public function test_users_lists_reject_unlisted_page_size(): void
    {
        $this->actingAsAdmin();

        $this->get('/admin/users?per_page=5000')
            ->assertInertia(fn (Assert $page) => $page
                ->where('perPage', 10)
                ->where('users.per_page', 10)
            );
        $this->get('/admin/users/trashed?per_page=abc')
            ->assertInertia(fn (Assert $page) => $page
                ->where('perPage', 10)
                ->where('users.per_page', 10)
            );
    }

    public function test_reference_lists_share_sort_and_page_size_with_users(): void
    {
        $this->actingAsAdmin();

        foreach (['roles' => 'Roles/Index'] as $list => $component) {
            $this->get("/admin/{$list}?sort=created_at&direction=desc&per_page=25")
                ->assertInertia(fn (Assert $page) => $page
                    ->component($component)
                    ->where('currentSort', 'created_at')
                    ->where('currentDirection', 'desc')
                    ->where('perPage', 25)
                    ->where("{$list}.per_page", 25)
                    ->where('perPageOptions', [10, 25, 50, 100])
                );

            $this->get("/admin/{$list}?sort=bogus&per_page=5000")
                ->assertInertia(fn (Assert $page) => $page
                    ->where('currentSort', 'name')
                    ->where('currentDirection', 'asc')
                    ->where('perPage', 10)
                );
        }
    }

    public function test_roles_index_sorts_by_user_count(): void
    {
        $this->actingAsAdmin();
        $busy = Role::create(['name' => 'busy', 'guard_name' => 'web']);
        Role::create(['name' => 'idle', 'guard_name' => 'web']);
        User::factory()->count(3)->create()->each->assignRole($busy);

        $this->get('/admin/roles?sort=users_count&direction=desc')
            ->assertInertia(fn (Assert $page) => $page
                ->where('roles.data.0.name', 'busy')
                ->where('roles.data.0.users_count', 3)
            );
    }
}
