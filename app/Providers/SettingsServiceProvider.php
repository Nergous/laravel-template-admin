<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Applies dynamic settings (Setting) onto the application configuration.
 *
 * Extracted from AppServiceProvider into a separate provider so that the
 * responsibility "settings from the DB override config" is named explicitly
 * rather than hidden in a generic boot(). On every web request it reads
 * Setting::grouped() (cached per request) and applies app.name / app.timezone /
 * session.lifetime onto config.
 */
class SettingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        try {
            if (! Schema::hasTable('settings')) {
                return;
            }

            // A single grouped() call for both groups (it is also memoized per
            // request — the blade favicon and the login limiter reuse it).
            $settings = Setting::grouped();
            $general = $settings['general'];
            $security = $settings['security'];

            config([
                'app.name' => $general['app_name'],
                'app.timezone' => $general['timezone'],
                'session.lifetime' => $security['session_lifetime'],
            ]);
            date_default_timezone_set($general['timezone']);
        } catch (\Throwable) {
        }
    }
}
