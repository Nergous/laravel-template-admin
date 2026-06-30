<?php

namespace Tests\Feature;

use App\Models\BotMessage;
use App\Models\BotMessageMedia;
use App\Models\Media;
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

        // Inline tags remain, block/dangerous ones are stripped out.
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

    public function test_update_attaches_media_in_order(): void
    {
        $this->actingAsUserWith(['bot-messages.edit']);
        $a = Media::create(['filename' => 'media/a.webp']);
        $b = Media::create(['filename' => 'media/b.webp']);

        $this->put(route('admin.bot-messages.update', 'welcome'), [
            'text' => 'with files',
            'is_active' => true,
            'media_ids' => [$b->id, $a->id],
        ])->assertRedirect();

        $this->assertDatabaseHas('bot_message_media', [
            'code' => 'welcome', 'media_id' => $b->id, 'position' => 0,
        ]);
        $this->assertDatabaseHas('bot_message_media', [
            'code' => 'welcome', 'media_id' => $a->id, 'position' => 1,
        ]);
    }

    public function test_update_replaces_previous_attachments(): void
    {
        $this->actingAsUserWith(['bot-messages.edit']);
        $a = Media::create(['filename' => 'media/a.webp']);
        $b = Media::create(['filename' => 'media/b.webp']);
        BotMessageMedia::create(['code' => 'welcome', 'media_id' => $a->id, 'position' => 0]);
        BotMessageMedia::create(['code' => 'welcome', 'media_id' => $b->id, 'position' => 1]);

        $this->put(route('admin.bot-messages.update', 'welcome'), [
            'text' => 'only b now',
            'media_ids' => [$b->id],
        ])->assertRedirect();

        $this->assertDatabaseMissing('bot_message_media', ['code' => 'welcome', 'media_id' => $a->id]);
        $this->assertDatabaseHas('bot_message_media', ['code' => 'welcome', 'media_id' => $b->id]);
    }

    public function test_update_clears_attachments_when_omitted(): void
    {
        $this->actingAsUserWith(['bot-messages.edit']);
        $a = Media::create(['filename' => 'media/a.webp']);
        BotMessageMedia::create(['code' => 'welcome', 'media_id' => $a->id, 'position' => 0]);

        $this->put(route('admin.bot-messages.update', 'welcome'), [
            'text' => 'no files',
            'media_ids' => [],
        ])->assertRedirect();

        $this->assertDatabaseMissing('bot_message_media', ['code' => 'welcome']);
    }

    public function test_update_rejects_unknown_media_id(): void
    {
        $this->actingAsUserWith(['bot-messages.edit']);

        $this->put(route('admin.bot-messages.update', 'welcome'), [
            'text' => 'x',
            'media_ids' => [999999],
        ])->assertSessionHasErrors('media_ids.0');

        $this->assertDatabaseMissing('bot_message_media', ['code' => 'welcome']);
    }

    public function test_update_rejects_too_many_attachments(): void
    {
        $this->actingAsUserWith(['bot-messages.edit']);
        config(['bot.max_attachments' => 2]);
        $ids = collect(range(1, 3))
            ->map(fn ($i) => Media::create(['filename' => "media/{$i}.webp"])->id)
            ->all();

        $this->put(route('admin.bot-messages.update', 'welcome'), [
            'text' => 'x',
            'media_ids' => $ids,
        ])->assertSessionHasErrors('media_ids');
    }

    public function test_reset_keeps_attachments(): void
    {
        $this->actingAsUserWith(['bot-messages.edit']);
        $a = Media::create(['filename' => 'media/a.webp']);
        BotMessage::create(['code' => 'welcome', 'text' => 'custom']);
        BotMessageMedia::create(['code' => 'welcome', 'media_id' => $a->id, 'position' => 0]);

        $this->delete(route('admin.bot-messages.reset', 'welcome'))->assertRedirect();

        // Resetting the text override must not drop attachments (separate concern).
        $this->assertDatabaseMissing('bot_messages', ['code' => 'welcome']);
        $this->assertDatabaseHas('bot_message_media', ['code' => 'welcome', 'media_id' => $a->id]);
    }

    public function test_deleting_media_cascades_attachment(): void
    {
        $this->actingAsUserWith(['bot-messages.edit']);
        $a = Media::create(['filename' => 'media/a.webp']);
        BotMessageMedia::create(['code' => 'welcome', 'media_id' => $a->id, 'position' => 0]);

        $a->delete();

        $this->assertDatabaseMissing('bot_message_media', ['media_id' => $a->id]);
    }

    public function test_index_includes_attachments(): void
    {
        $this->actingAsUserWith(['bot-messages.view']);
        $a = Media::create(['filename' => 'media/a.webp']);
        BotMessageMedia::create(['code' => 'welcome', 'media_id' => $a->id, 'position' => 0]);

        $this->get(route('admin.bot-messages.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('BotMessages/Index')
                ->where('messages.0.code', 'welcome')
                ->has('messages.0.attachments', 1)
                ->where('messages.0.attachments.0.id', $a->id)
            );
    }
}
