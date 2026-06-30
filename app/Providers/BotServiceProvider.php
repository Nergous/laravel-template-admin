<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Provider for the optional bot module. Active only when config('bot.enabled').
 * Loads the bot migrations from a separate folder so that `php artisan migrate`
 * does not see them when the module is disabled.
 */
class BotServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! config('bot.enabled')) {
            return;
        }

        $this->loadMigrationsFrom(database_path('migrations/bot'));
    }
}
