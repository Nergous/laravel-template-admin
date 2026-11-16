<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/** Blade directives that print sanitized HTML in site templates (SiteServiceProvider). */
class HtmlDirectivesTest extends TestCase
{
    public function test_directives_print_sanitized_html(): void
    {
        $value = '<p onclick="x()">a <a href="https://e.com">b</a></p><script>alert(1)</script>';

        $this->assertSame('<p>a <a href="https://e.com">b</a></p>', Blade::render('@html($value)', ['value' => $value]));
        $this->assertSame('<p>a b</p>', Blade::render('@htmlWithoutLinks($value)', ['value' => $value]));
        $this->assertSame('a <a href="https://e.com">b</a>', Blade::render('@htmlInline($value)', ['value' => $value]));
        $this->assertSame("x<br>\n1 &lt; 2", Blade::render('@htmlText($value)', ['value' => "x\n1 < 2"]));
        $this->assertSame('', Blade::render('@html($value)', ['value' => null]));
    }
}
