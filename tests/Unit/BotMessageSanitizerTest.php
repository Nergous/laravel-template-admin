<?php

namespace Tests\Unit;

use App\Support\BotMessageSanitizer;
use Tests\TestCase;

class BotMessageSanitizerTest extends TestCase
{
    public function test_keeps_text_of_block_tags_from_rich_editor(): void
    {
        // NRichText (tiptap) wraps a line in <p>. symfony/html-sanitizer v8 would
        // otherwise drop the whole content — the bug that blocked saving.
        $this->assertSame('Привет мир', BotMessageSanitizer::sanitize('<p>Привет мир</p>'));
        $this->assertSame('текст', BotMessageSanitizer::sanitize('<div>текст</div>'));
    }

    public function test_paragraphs_and_breaks_become_newlines(): void
    {
        $this->assertSame("a\nb", BotMessageSanitizer::sanitize('<p>a</p><p>b</p>'));
        $this->assertSame("строка\nвторая", BotMessageSanitizer::sanitize('<p>строка<br>вторая</p>'));
    }

    public function test_contenteditable_bare_line_then_div(): void
    {
        // Chrome contenteditable output: first line bare, next lines wrapped in <div>.
        $this->assertSame("a\nb", BotMessageSanitizer::sanitize('a<div>b</div>'));
        $this->assertSame("a\nb\nc", BotMessageSanitizer::sanitize('a<div>b</div><div>c</div>'));
        $this->assertSame("a\nb", BotMessageSanitizer::sanitize('<div>a</div><div>b</div>'));
    }

    public function test_preserves_allowed_inline_markup(): void
    {
        $this->assertSame(
            '<b>жирный</b> и <i>курсив</i>',
            BotMessageSanitizer::sanitize('<p><b>жирный</b> и <i>курсив</i></p>'),
        );
    }

    public function test_plain_text_is_unchanged(): void
    {
        $this->assertSame('Привет, я бот.', BotMessageSanitizer::sanitize('Привет, я бот.'));
    }

    public function test_drops_script_with_contents(): void
    {
        $this->assertSame('ok', BotMessageSanitizer::sanitize('<p>ok</p><script>alert(1)</script>'));
    }
}
