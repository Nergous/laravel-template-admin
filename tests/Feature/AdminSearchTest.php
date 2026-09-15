<?php

namespace Tests\Feature;

use App\Models\BotMessage;
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

    public function test_finds_bot_messages_by_catalog_label_and_code(): void
    {
        $this->actingAsUserWith(['bot-messages.view']);

        foreach (['Приветствие', 'welcome'] as $query) {
            $found = $this->search($query);
            $this->assertCount(1, $found);
            $this->assertSame('bot-message', $found[0]['type']);
            $this->assertSame('Приветствие', $found[0]['label']);
            $this->assertSame(route('admin.bot-messages.index'), $found[0]['url']);
        }
    }

    public function test_finds_bot_messages_by_override_text(): void
    {
        $this->actingAsUserWith(['bot-messages.view']);
        BotMessage::create(['code' => 'welcome', 'text' => 'Unique override text', 'is_active' => true]);

        $found = $this->search('Unique override');

        $this->assertCount(1, $found);
        $this->assertSame('bot-message', $found[0]['type']);
    }

    public function test_hides_bot_messages_without_view_permission(): void
    {
        $this->actingAsUserWith(['bot-messages.edit']);

        $this->assertSame([], $this->search('Приветствие'));
    }

    public function test_hides_bot_messages_when_module_is_disabled(): void
    {
        $this->actingAsUserWith(['bot-messages.view']);
        config(['bot.enabled' => false]);

        $this->assertSame([], $this->search('Приветствие'));
    }
}
