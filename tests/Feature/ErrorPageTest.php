<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ErrorPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * When debug is off (as in production), an access denial in the SPA should
     * return a styled Inertia Error page rather than the default Symfony screen.
     */
    public function test_forbidden_renders_inertia_error_page_when_debug_off(): void
    {
        config(['app.debug' => false]);
        $this->actingAsUserWith([]); // no permissions → 403 on the section

        $this->get(route('admin.users.index'))
            ->assertStatus(403)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Error')
                ->where('status', 403));
    }

    /**
     * JSON requests (e.g. global search/polling) must NOT be swapped for an
     * HTML error page — the API client expects JSON.
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
