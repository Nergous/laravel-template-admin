<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

/**
 * Накат динамических настроек (Setting) на конфигурацию приложения.
 *
 * Вынесен из AppServiceProvider в отдельный провайдер, чтобы ответственность
 * «настройки из БД переопределяют config» была названа явно, а не пряталась в
 * общем boot(). На каждом web-запросе читает Setting::grouped() (кэшируется на
 * запрос) и накатывает app.name / app.timezone / session.lifetime на config.
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

            // Один вызов grouped() на обе группы (он же мемоизируется на запрос —
            // blade-favicon и лимитер логина переиспользуют его).
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
