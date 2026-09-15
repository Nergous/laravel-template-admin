<?php

namespace Tests\Feature;

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
}
