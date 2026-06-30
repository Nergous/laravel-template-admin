<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Главный сервис-провайдер приложения.
 *
 * Сервис-провайдеры — точка начальной загрузки Laravel: register() связывает
 * сервисы в контейнере, boot() выполняется после регистрации всех провайдеров.
 * Здесь собрана общеприкладная инициализация, не заслуживающая отдельного
 * провайдера: принудительный HTTPS в production и лимитер попыток входа.
 */
class AppServiceProvider extends ServiceProvider
{
    /** Регистрация привязок в контейнере. Сейчас пусто — кастомных биндингов нет. */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap после регистрации провайдеров: форсирует HTTPS в production
     * и регистрирует rate-limiter «login» (лимит из настройки security.login_throttle).
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');

            // в production админка всегда под HTTPS, поэтому
            // по умолчанию помечаем cookie сессии флагом Secure (браузер не отдаёт
            // её по HTTP).
            if (config('session.secure') === null) {
                config(['session.secure' => true]);
            }
        }

        // Лимит попыток входа управляется настройкой security.login_throttle.
        // Лимитер регистрируется всегда; значение читается в момент запроса.
        RateLimiter::for('login', function (Request $request) {
            $max = 5;
            try {
                $max = (int) (Setting::grouped()['security']['login_throttle'] ?? 5);
            } catch (\Throwable) {
                // таблица настроек ещё не создана — используем дефолт
            }

            // Ключ по паре email+ip: чтобы получить «свежее ведро», атакующему нужно
            // менять обе оси сразу. IP больше не подделать (trustProxies сужен в
            // bootstrap/app.php), поэтому варьирование X-Forwarded-For не обходит
            // лимит. Ключ email+ip (как в Breeze/Fortify) не вызывает
            // массовой блокировки: попытки атакующего по одному IP не лочат жертву
            // с другого IP.
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(max(1, $max))->by($email.'|'.$request->ip());
        });
    }
}
