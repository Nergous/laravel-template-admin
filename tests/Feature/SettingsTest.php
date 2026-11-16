<?php

namespace Tests\Feature;

use App\Enums\AccentPreset;
use App\Models\Setting;
use App\Services\System\AccentColor;
use App\Services\System\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function fullPayload(array $overrides = []): array
    {
        $settings = [
            'general' => [
                'app_name' => 'nergous-ui-vue',
                'timezone' => 'Europe/Moscow',
                'favicon' => '',
            ],
            'seo' => [
                'meta_title_template' => '%s — test',
                'meta_description' => 'desc',
                'canonical_domain' => 'https://example.test',
                'og_image' => '',
                'indexable' => true,
                'sitemap' => true,
            ],
            'security' => [
                'session_lifetime' => 120,
                'login_throttle' => 5,
            ],
            'appearance' => [
                'accent' => 'blue',
            ],
        ];

        return array_replace_recursive($settings, $overrides);
    }

    public function test_admin_can_save_settings(): void
    {
        $this->actingAsAdmin();

        $this->from(route('admin.settings.index'))
            ->put(route('admin.settings.update'), [
                'settings' => $this->fullPayload([
                    'general' => ['app_name' => 'Changed'],
                    'security' => ['session_lifetime' => 200],
                ]),
            ])
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('settings', [
            'key' => 'general.app_name',
            'group' => 'general',
            'type' => 'string',
            'value' => 'Changed',
        ]);
        $this->assertDatabaseHas('settings', [
            'key' => 'security.session_lifetime',
            'type' => 'int',
            'value' => '200',
        ]);
    }

    public function test_saving_settings_twice_upserts_in_place(): void
    {
        $this->actingAsAdmin();

        $this->put(route('admin.settings.update'), [
            'settings' => $this->fullPayload(['general' => ['app_name' => 'First']]),
        ])->assertRedirect();

        $this->put(route('admin.settings.update'), [
            'settings' => $this->fullPayload(['general' => ['app_name' => 'Second']]),
        ])->assertRedirect();

        $this->assertSame(1, Setting::where('key', 'general.app_name')->count());
        $this->assertSame('Second', Setting::where('key', 'general.app_name')->first()->value);
    }

    public function test_settings_are_memoized_within_request(): void
    {
        $settings = app(Settings::class);
        $settings->set('general', 'app_name', 'Memoized');
        $first = $settings->all();

        // Bypass the service: neither the cache nor the table is read again in this request.
        Setting::query()->where('key', 'general.app_name')->update(['value' => 'Changed']);
        Cache::flush();
        $second = app(Settings::class)->all();

        $this->assertSame('Memoized', $first['general']['app_name']);
        $this->assertSame($first, $second);
    }

    public function test_flush_cache_clears_request_memo(): void
    {
        app(Settings::class)->set('general', 'app_name', 'Before');
        $this->assertSame('Before', app(Settings::class)->all()['general']['app_name']);

        app(Settings::class)->set('general', 'app_name', 'After');
        app(Settings::class)->flush();

        $this->assertSame('After', app(Settings::class)->all()['general']['app_name']);
    }

    public function test_saved_name_reaches_admin_inertia_props_and_session_lifetime(): void
    {
        app(Settings::class)->setMany([
            'general' => ['app_name' => 'Из настроек'],
            'security' => ['session_lifetime' => 7],
        ]);

        $this->get(route('login'))->assertOk();
        $this->assertSame(7, config('session.lifetime'));

        $this->actingAsAdmin();

        $this->get(route('admin.dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('appName', 'Из настроек'));
    }

    public function test_favicon_rejects_non_url_value(): void
    {
        $this->actingAsAdmin();

        $this->from(route('admin.settings.index'))
            ->put(route('admin.settings.update'), [
                'settings' => $this->fullPayload(['general' => ['favicon' => 'javascript:alert(1)']]),
            ])
            ->assertSessionHasErrors('settings.general.favicon');
    }

    public function test_og_image_rejects_data_uri(): void
    {
        $this->actingAsAdmin();

        $this->from(route('admin.settings.index'))
            ->put(route('admin.settings.update'), [
                'settings' => $this->fullPayload(['seo' => ['og_image' => 'data:text/html,evil']]),
            ])
            ->assertSessionHasErrors('settings.seo.og_image');
    }

    public function test_favicon_accepts_relative_media_path(): void
    {
        $this->actingAsAdmin();

        $this->put(route('admin.settings.update'), [
            'settings' => $this->fullPayload(['general' => ['favicon' => '/storage/media/abc.webp']]),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('settings', [
            'key' => 'general.favicon',
            'value' => '/storage/media/abc.webp',
        ]);
    }

    public function test_favicon_accepts_absolute_https_url(): void
    {
        $this->actingAsAdmin();

        $this->put(route('admin.settings.update'), [
            'settings' => $this->fullPayload(['general' => ['favicon' => 'https://cdn.example.test/favicon.ico']]),
        ])->assertSessionHasNoErrors();
    }

    public function test_timezone_rejects_invalid_identifier(): void
    {
        $this->actingAsAdmin();

        $this->from(route('admin.settings.index'))
            ->put(route('admin.settings.update'), [
                'settings' => $this->fullPayload(['general' => ['timezone' => 'Foo/Bar']]),
            ])
            ->assertSessionHasErrors('settings.general.timezone');
    }

    public function test_timezone_accepts_valid_identifier(): void
    {
        $this->actingAsAdmin();

        $this->put(route('admin.settings.update'), [
            'settings' => $this->fullPayload(['general' => ['timezone' => 'America/New_York']]),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('settings', [
            'key' => 'general.timezone',
            'value' => 'America/New_York',
        ]);
    }

    public function test_canonical_domain_rejects_a_path(): void
    {
        $this->actingAsAdmin();

        $this->from(route('admin.settings.index'))
            ->put(route('admin.settings.update'), [
                'settings' => $this->fullPayload(['seo' => ['canonical_domain' => 'https://example.test/path']]),
            ])
            ->assertSessionHasErrors('settings.seo.canonical_domain');
    }

    public function test_fields_outside_the_settings_page_are_dropped(): void
    {
        $this->actingAsAdmin();

        $this->get(route('admin.settings.index'))
            ->assertInertia(fn ($page) => $page
                ->has('settings.general.app_name')
                ->missing('settings.public')
                ->missing('settings.archive'));

        // An unknown group sent to the settings is dropped, not saved.
        $this->put(route('admin.settings.update'), [
            'settings' => $this->fullPayload(['public' => ['footer_email' => 'x@example.test']]),
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('settings', ['key' => 'public.footer_email']);
    }

    public function test_set_many_ignores_keys_outside_schema(): void
    {
        app(Settings::class)->setMany([
            'general' => ['app_name' => 'Known'],
            'bogus' => ['nope' => 'x'],
            'security' => ['unknown_key' => 'y'],
        ]);

        $this->assertDatabaseHas('settings', ['key' => 'general.app_name', 'value' => 'Known']);
        $this->assertDatabaseMissing('settings', ['key' => 'bogus.nope']);
        $this->assertDatabaseMissing('settings', ['key' => 'security.unknown_key']);
        $this->assertSame(1, Setting::count());
    }

    public function test_accent_color_is_saved_and_rendered_on_the_admin(): void
    {
        $this->actingAsAdmin();

        $this->get(route('admin.settings.index'))
            ->assertSee('data-accent="blue"', false)
            ->assertInertia(fn ($page) => $page
                ->where('accent', 'blue')
                ->where('settings.appearance.accent', 'blue')
                ->has('accents', count(AccentPreset::cases())));

        $this->from(route('admin.settings.index'))
            ->put(route('admin.settings.update'), [
                'settings' => $this->fullPayload(['appearance' => ['accent' => 'green']]),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(AccentPreset::Green, app(AccentColor::class)->current());
        $this->get(route('admin.dashboard'))
            ->assertSee('data-accent="green"', false)
            ->assertInertia(fn ($page) => $page->where('accent', 'green'));
    }

    public function test_accent_color_rejects_unknown_preset(): void
    {
        $this->actingAsAdmin();

        $this->from(route('admin.settings.index'))
            ->put(route('admin.settings.update'), [
                'settings' => $this->fullPayload(['appearance' => ['accent' => 'red;}body{display:none']]),
            ])
            ->assertSessionHasErrors('settings.appearance.accent');

        $this->assertDatabaseMissing('settings', ['key' => 'appearance.accent']);
    }

    public function test_unknown_stored_accent_falls_back_to_default(): void
    {
        app(Settings::class)->set('appearance', 'accent', 'neon');

        $this->assertSame(AccentColor::DEFAULT, app(AccentColor::class)->current());
    }

    public function test_every_accent_preset_has_light_and_dark_tokens(): void
    {
        $css = (string) file_get_contents(resource_path('js/admin/accents.css'));

        foreach (AccentPreset::values() as $key) {
            $this->assertStringContainsString("[data-accent=\"{$key}\"] {", $css, "light tokens of {$key}");
            $this->assertStringContainsString("[data-theme=\"dark\"][data-accent=\"{$key}\"]", $css, "dark tokens of {$key}");
        }
    }
}
