<?php

namespace Tests\Feature;

use App\Models\BotMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BotMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_renders_catalog_for_authorized_user(): void
    {
        $this->actingAsUserWith(['bot-messages.view']);

        $this->get(route('admin.bot-messages.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('BotMessages/Index')
                ->has('messages')
            );
    }

    public function test_index_forbidden_without_permission(): void
    {
        $this->actingAsUserWith([]);

        $this->get(route('admin.bot-messages.index'))->assertForbidden();
    }

    public function test_update_upserts_override(): void
    {
        $this->actingAsUserWith(['bot-messages.edit']);

        $this->put(route('admin.bot-messages.update', 'welcome'), [
            'text' => 'Новый привет',
            'is_active' => true,
        ])->assertRedirect();

        $this->assertDatabaseHas('bot_messages', [
            'code' => 'welcome',
            'text' => 'Новый привет',
            'is_active' => 1,
        ]);
    }

    public function test_update_sanitizes_html_to_inline_subset(): void
    {
        $this->actingAsUserWith(['bot-messages.edit']);

        $this->put(route('admin.bot-messages.update', 'welcome'), [
            'text' => '<b>Привет</b><h2>заголовок</h2>'
                .'<a href="javascript:alert(1)" onclick="x()">ссылка</a>'
                .'<script>bad()</script>',
            'is_active' => true,
        ])->assertRedirect();

        $stored = BotMessage::where('code', 'welcome')->value('text');

        // Инлайн-теги остаются, блочные/опасные — вырезаны.
        $this->assertStringContainsString('<b>Привет</b>', $stored);
        $this->assertStringContainsString('ссылка</a>', $stored);
        $this->assertStringNotContainsString('<h2>', $stored);
        $this->assertStringNotContainsString('<script>', $stored);
        $this->assertStringNotContainsString('bad()', $stored);
        $this->assertStringNotContainsString('onclick', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
    }

    public function test_update_strips_non_whitelisted_attributes_from_inline_tags(): void
    {
        $this->actingAsUserWith(['bot-messages.edit']);

        $this->put(route('admin.bot-messages.update', 'welcome'), [
            'text' => '<b style="color:red" class="x" data-y="z">жирный</b>',
            'is_active' => true,
        ])->assertRedirect();

        $stored = BotMessage::where('code', 'welcome')->value('text');

        $this->assertStringContainsString('<b>жирный</b>', $stored);
        $this->assertStringNotContainsString('style', $stored);
        $this->assertStringNotContainsString('class', $stored);
        $this->assertStringNotContainsString('data-y', $stored);
    }

    public function test_update_drops_dangerous_link_schemes(): void
    {
        $this->actingAsUserWith(['bot-messages.edit']);

        $this->put(route('admin.bot-messages.update', 'welcome'), [
            'text' => '<a href="data:text/html,evil">d</a><a href="javascript:alert(1)">j</a>',
            'is_active' => true,
        ])->assertRedirect();

        $stored = BotMessage::where('code', 'welcome')->value('text');

        $this->assertStringNotContainsString('data:', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
    }

    public function test_update_preserves_safe_links(): void
    {
        $this->actingAsUserWith(['bot-messages.edit']);

        $this->put(route('admin.bot-messages.update', 'welcome'), [
            'text' => 'смотри <a href="https://example.com">сайт</a>',
            'is_active' => true,
        ])->assertRedirect();

        $stored = BotMessage::where('code', 'welcome')->value('text');

        $this->assertStringContainsString('<a href="https://example.com">сайт</a>', $stored);
    }

    public function test_update_requires_edit_permission(): void
    {
        $this->actingAsUserWith(['bot-messages.view']);

        $this->put(route('admin.bot-messages.update', 'welcome'), ['text' => 'x'])
            ->assertForbidden();
    }

    public function test_reset_deletes_override(): void
    {
        $this->actingAsUserWith(['bot-messages.edit']);
        BotMessage::create(['code' => 'welcome', 'text' => 'custom']);

        $this->delete(route('admin.bot-messages.reset', 'welcome'))->assertRedirect();

        $this->assertDatabaseMissing('bot_messages', ['code' => 'welcome']);
    }

    public function test_unknown_code_returns_404(): void
    {
        $this->actingAsUserWith(['bot-messages.edit']);

        $this->put(route('admin.bot-messages.update', 'nope'), ['text' => 'x'])
            ->assertNotFound();
    }

    public function test_routes_404_when_bot_disabled(): void
    {
        config(['bot.enabled' => false]);
        $this->actingAsUserWith(['bot-messages.view']);

        $this->get(route('admin.bot-messages.index'))->assertNotFound();
    }
}
