<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ErrorPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Когда отладка выключена (как в production), отказ доступа в SPA должен
     * отдавать стилизованную Inertia-страницу Error, а не дефолтный Symfony-экран.
     */
    public function test_forbidden_renders_inertia_error_page_when_debug_off(): void
    {
        config(['app.debug' => false]);
        $this->actingAsUserWith([]); // без прав → 403 на разделе

        $this->get(route('admin.users.index'))
            ->assertStatus(403)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Error')
                ->where('status', 403));
    }

    /**
     * JSON-запросы (например, глобальный поиск/поллинг) НЕ должны подменяться
     * HTML-страницей ошибки — API-клиент ждёт JSON.
     */
    public function test_json_request_keeps_json_error_when_debug_off(): void
    {
        config(['app.debug' => false]);
        $this->actingAsUserWith([]);

        $this->getJson(route('admin.users.index'))
            ->assertStatus(403)
            ->assertJsonStructure(['message']);
    }
}
