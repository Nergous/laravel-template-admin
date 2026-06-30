<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Провайдер опционального модуля бота. Активен только при config('bot.enabled').
 * Подключает миграции бота из отдельной папки, чтобы при выключенном модуле
 * `php artisan migrate` их не видел.
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
