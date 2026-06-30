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
 * The application's main service provider.
 *
 * Service providers are Laravel's bootstrap point: register() binds services in
 * the container, boot() runs after all providers are registered. This collects
 * general application-wide initialization that does not warrant a dedicated
 * provider: forcing HTTPS in production and the login attempt limiter.
 */
class AppServiceProvider extends ServiceProvider
{
    /** Registering bindings in the container. Currently empty — there are no custom bindings. */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap after providers are registered: forces HTTPS in production and
     * registers the "login" rate limiter (limit from the security.login_throttle setting).
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');

            // in production the admin panel is always over HTTPS, so
            // by default we mark the session cookie with the Secure flag (the browser
            // does not send it over HTTP).
            if (config('session.secure') === null) {
                config(['session.secure' => true]);
            }
        }

        // The login attempt limit is controlled by the security.login_throttle setting.
        // The limiter is always registered; the value is read at request time.
        RateLimiter::for('login', function (Request $request) {
            $max = 5;
            try {
                $max = (int) (Setting::grouped()['security']['login_throttle'] ?? 5);
            } catch (\Throwable) {
                // the settings table does not exist yet — use the default
            }

            // Key by the email+ip pair: to get a "fresh bucket", an attacker has to
            // vary both axes at once. The IP can no longer be spoofed (trustProxies is
            // narrowed in bootstrap/app.php), so varying X-Forwarded-For does not
            // bypass the limit. The email+ip key (as in Breeze/Fortify) does not cause
            // mass lockout: an attacker's attempts from one IP do not lock out a victim
            // on a different IP.
            $email = Str::lower((string) $request->input('email'));

            return Limit::perMinute(max(1, $max))->by($email.'|'.$request->ip());
        });
    }
}
