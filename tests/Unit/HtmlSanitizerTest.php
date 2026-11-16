<?php

namespace Tests\Unit;

use App\Support\Html\HtmlSanitizer;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $html;

    protected function setUp(): void
    {
        parent::setUp();
        $this->html = new HtmlSanitizer(new Repository(new ArrayStore));
    }

    public function test_keeps_images_tables_and_alignment(): void
    {
        $html = $this->html->clean(
            '<p style="text-align: center; color: red" class="x">Текст <img src="/storage/media/2020/05/a.jpg" alt="A" width="300"></p>'
            .'<table border="1"><tbody><tr><td colspan="2">ячейка</td></tr></tbody></table>'
        );

        $this->assertSame(
            '<p style="text-align: center">Текст <img src="/storage/media/2020/05/a.jpg" alt="A" width="300"></p>'
            .'<table border="1"><tbody><tr><td colspan="2">ячейка</td></tr></tbody></table>',
            $html
        );
    }

    public function test_removes_scripts_handlers_and_unsafe_urls(): void
    {
        $html = $this->html->clean(
            '<p onclick="x()">a<script>alert(1)</script><a href="javascript:alert(1)">b</a>'
            .'<img src="data:image/png;base64,AAA" onerror="x()"><font>c</font><a href="https://e.com" target="_blank">d</a></p>'
        );

        $this->assertSame(
            '<p>a<a>b</a>c<a href="https://e.com" target="_blank" rel="noopener noreferrer">d</a></p>',
            $html
        );
    }

    /**
     * Stray closing tags in old texts used to close the inner wrapper, and
     * everything after them was lost (and saved truncated).
     */
    public function test_stray_closing_tags_do_not_truncate_the_text(): void
    {
        $this->assertSame('<p>a</p><p>b</p>', $this->html->clean('<p>a</p></div><p>b</p>'));
        $this->assertSame('<p>a</p><p>b</p>', $this->html->clean('<p>a</p></body><p>b</p>'));
        $this->assertSame('<p>a</p><p>b</p><p>c</p>', $this->html->clean('<p>a</p></html><p>b</p></body></html><p>c</p>'));
        $this->assertSame(
            '<p>x</p><p>y</p>',
            $this->html->clean('<html><head><title>t</title></head><body><p>x</p></body></html><p>y</p>')
        );
    }

    public function test_cleaning_is_idempotent_and_memoized_result_is_stable(): void
    {
        $html = str_repeat('<p style="text-align: center">Абзац <b>текст</b></p>', 40).'</div><p>хвост</p>';
        $once = $this->html->clean($html);

        $this->assertStringEndsWith('<p>хвост</p>', $once);
        $this->assertSame($once, $this->html->clean($html));
        $this->assertSame($once, $this->html->clean($once));
    }

    public function test_http_images_are_upgraded_and_svg_content_is_dropped(): void
    {
        $this->assertSame(
            '<img src="https://e.com/a.jpg"><a href="http://e.com">l</a><p>k</p>',
            $this->html->clean('<img src="http://e.com/a.jpg"><a href="http://e.com">l</a><svg><a href="https://e.com">s</a></svg><p>k</p>')
        );
    }

    public function test_mixed_fields_keep_plain_text_and_clean_html(): void
    {
        $this->assertSame("Иванов И.И.,\nПетров П.П. & Co", $this->html->cleanMixed("Иванов И.И.,\nПетров П.П. & Co"));
        $this->assertSame('<b>Иванов</b>', $this->html->cleanMixed('<b onclick="x()">Иванов</b><script>alert(1)</script>'));
        $this->assertNull($this->html->cleanMixed(null));
    }

    public function test_text_without_links_and_inline_variants(): void
    {
        $this->assertSame('<p>до ссылка после</p>', $this->html->cleanWithoutLinks('<p>до <a href="https://e.com">ссылка</a> после</p>'));
        $this->assertSame('<b>а</b> &lt;б&gt;', $this->html->cleanInline('<p><b>а</b> &lt;б&gt;</p>'));
        $this->assertSame("а<br>\nб &amp; в", $this->html->cleanInline("а\nб & в"));
        $this->assertNull($this->html->cleanWithoutLinks(null));
    }
}
