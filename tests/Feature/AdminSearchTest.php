<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSearchTest extends TestCase
{
    use RefreshDatabase;

    private function search(string $query): array
    {
        return $this->getJson(route('admin.search', ['q' => $query]))
            ->assertOk()->json('results');
    }

    public function test_short_query_returns_no_results(): void
    {
        $this->actingAsAdmin();

        $this->assertSame([], $this->search('a'));
    }

    public function test_finds_users_with_permission(): void
    {
        $this->actingAsUserWith(['users.view']);
        User::factory()->create(['name' => 'Searchable User']);

        $found = $this->search('Searchable');

        $this->assertCount(1, $found);
        $this->assertSame('user', $found[0]['type']);
        $this->assertSame('Searchable User', $found[0]['label']);
    }

    public function test_finds_roles_with_permission(): void
    {
        $this->actingAsUserWith(['roles.view']);
        $role = Role::create(['name' => 'Архитектор', 'guard_name' => 'web']);

        $found = collect($this->search('Архит'))->where('type', 'role')->values()->all();

        $this->assertCount(1, $found);
        $this->assertSame('Архитектор', $found[0]['label']);
        $this->assertSame(route('admin.roles.show', $role), $found[0]['url']);
    }

    public function test_sections_without_permission_are_not_searched(): void
    {
        $this->actingAsUserWith(['roles.view']);
        User::factory()->create(['name' => 'Searchable User']);

        $this->assertSame([], $this->search('Searchable'));
    }
}
