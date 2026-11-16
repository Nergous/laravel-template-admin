<?php

namespace Tests\Unit;

use App\Support\Html\SafeStyle;
use App\Support\Html\SafeUrl;
use PHPUnit\Framework\TestCase;

class SafeUrlAndStyleTest extends TestCase
{
    public function test_links_allow_web_mail_phone_and_relative_addresses(): void
    {
        $this->assertSame('http://e.com', SafeUrl::link('http://e.com'));
        $this->assertSame('mailto:a@e.com', SafeUrl::link('mailto:a@e.com'));
        $this->assertSame('files/x.pdf', SafeUrl::link('files/x.pdf'));
        $this->assertSame('https://e.com/x', SafeUrl::link('//e.com/x'));
        $this->assertSame('', SafeUrl::link('javascript:alert(1)'));
        $this->assertSame('', SafeUrl::link('java'."\t".'script:alert(1)'));
        $this->assertSame('', SafeUrl::link('a:b/c'));
    }

    public function test_images_allow_only_web_addresses_upgraded_to_https(): void
    {
        $this->assertSame('https://e.com/a.jpg', SafeUrl::image('http://e.com/a.jpg'));
        $this->assertSame('https://e.com/a.jpg', SafeUrl::image('HTTP://e.com/a.jpg'));
        $this->assertSame('/storage/a.jpg', SafeUrl::image('/storage/a.jpg'));
        $this->assertSame('', SafeUrl::image('mailto:a@e.com'));
        $this->assertSame('', SafeUrl::image('data:image/png;base64,AAA'));
    }

    public function test_style_keeps_only_layout_properties_with_plain_values(): void
    {
        $this->assertSame('text-align: center; width: 50%', SafeStyle::filter('TEXT-ALIGN: center; color: red; width: 50%'));
        $this->assertSame('', SafeStyle::filter('width: expression(alert(1)); float: url(x)'));
    }
}
