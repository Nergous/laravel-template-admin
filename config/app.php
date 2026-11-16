<?php

return [

    /*
    | Default application name. The admin panel and the site show the name from
    | the general.app_name setting; this value is seen where there is no
    | database (500/503 error pages, the console).
    */

    'name' => env('APP_NAME', 'Admin Panel'),

    /*
    | Application environment (production, local, testing). Set in .env.
    */

    'env' => env('APP_ENV', 'production'),

    /*
    | Debug mode: shows errors with stack traces when enabled. Off in production.
    */

    'debug' => (bool) env('APP_DEBUG', false),

    /*
    | Root URL of the application; Artisan commands need it to generate links.
    */

    'url' => env('APP_URL', 'http://localhost'),

    /*
    | Timestamps are stored in UTC for web, queue and scheduler; do not change it.
    | The display zone comes from the general.timezone setting (App\Services\System\DisplayTime).
    */

    'timezone' => 'UTC',

    /*
    | Default and fallback translation locale; the Faker locale is for test data.
    */

    'locale' => env('APP_LOCALE', 'ru'),

    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'ru'),

    'faker_locale' => env('APP_FAKER_LOCALE', 'ru_RU'),

    /*
    | Encryption cipher; do not change it, or encrypted data becomes unreadable.
    */

    'cipher' => 'AES-256-CBC',

    /*
    | Encryption key (APP_KEY, a random 32-character string). Old keys in
    | APP_PREVIOUS_KEYS (comma-separated) decrypt data after a key rotation.
    */

    'key' => env('APP_KEY'),

    'previous_keys' => [
        ...array_filter(
            explode(',', (string) env('APP_PREVIOUS_KEYS', ''))
        ),
    ],

    /*
    | Maintenance mode driver: file or cache (cache works across several servers).
    | store — the cache store for the cache driver.
    */

    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

];
