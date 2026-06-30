<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private function fullPayload(array $overrides = []): array
    {
        $settings = [
            'general' => [
                'app_name' => 'nergous-cit',
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

    public function test_grouped_is_memoized_within_request(): void
    {
        Cache::shouldReceive('rememberForever')
            ->once()
            ->andReturn(['general.app_name' => ['string', 'Memoized']]);

        $first = Setting::grouped();
        $second = Setting::grouped();

        $this->assertSame('Memoized', $first['general']['app_name']);
        $this->assertSame($first, $second);
    }

    public function test_flush_cache_clears_request_memo(): void
    {
        Setting::set('general', 'app_name', 'Before');
        $this->assertSame('Before', Setting::grouped()['general']['app_name']);

        Setting::set('general', 'app_name', 'After');
        Setting::flushCache();

        $this->assertSame('After', Setting::grouped()['general']['app_name']);
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

    public function test_set_many_ignores_keys_outside_schema(): void
    {
        Setting::setMany([
            'general' => ['app_name' => 'Known'],
            'bogus' => ['nope' => 'x'],
            'security' => ['unknown_key' => 'y'],
        ]);

        $this->assertDatabaseHas('settings', ['key' => 'general.app_name', 'value' => 'Known']);
        $this->assertDatabaseMissing('settings', ['key' => 'bogus.nope']);
        $this->assertDatabaseMissing('settings', ['key' => 'security.unknown_key']);
        $this->assertSame(1, Setting::count());
    }
}
