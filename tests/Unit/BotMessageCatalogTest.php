<?php

namespace Tests\Unit;

use App\Support\BotMessageCatalog;
use Tests\TestCase;

class BotMessageCatalogTest extends TestCase
{
    public function test_catalog_loads_welcome_from_registry(): void
    {
        $codes = BotMessageCatalog::codes();

        $this->assertContains('welcome', $codes);
        $this->assertNotEmpty(BotMessageCatalog::find('welcome')['default']);
        $this->assertNull(BotMessageCatalog::find('does_not_exist'));
    }
}
