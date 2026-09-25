<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\ServiceProvider;

/**
 * Applies dynamic settings (Setting) onto the application configuration.
 *
 * Extracted from AppServiceProvider into a separate provider so that the
 * responsibility "settings from the DB override config" is named explicitly
 * rather than hidden in a generic boot(). On every web request it reads
 * Setting::grouped() (cached per request) and applies app.name /
 * session.lifetime onto config.
 *
 * The general.timezone setting is a DISPLAY time zone only: it is shared with
 * the frontend (HandleInertiaRequests) and used when formatting dates. The
 * application keeps config('app.timezone') (UTC) for storage, so web requests,
 * queue workers, and the scheduler write timestamps in the same zone.
 */
class SettingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        try {
            // A single grouped() call (it is also memoized per request — the blade
            // favicon and the login limiter reuse it).
            // A missing settings table (before migrations) throws and is ignored
            // below; no schema lookup on every request.
            $settings = Setting::grouped();

            config([
                'app.name' => $settings['general']['app_name'],
                'session.lifetime' => max(1, (int) $settings['security']['session_lifetime']),
            ]);
        } catch (\Throwable) {
        }
    }

    /**
     * The display time zone from settings, falling back to the storage zone
     * when the settings table is missing or the value is invalid.
     */
    public static function displayTimezone(): string
    {
        try {
            $timezone = (string) Setting::value('general', 'timezone');

            return in_array($timezone, timezone_identifiers_list(), true)
                ? $timezone
                : (string) config('app.timezone');
        } catch (\Throwable) {
            return (string) config('app.timezone');
        }
    }
}
