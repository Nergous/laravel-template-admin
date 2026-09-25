<?php

namespace App\Providers;

use App\Models\Setting;
use App\Services\QueueStats;
use App\Support\Sitemap;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

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
    /** How many times the per-account login limit one IP may use across all emails. */
    public const LOGIN_IP_MULTIPLIER = 4;

    /** Container bindings: the sitemap registry is shared so providers can add sources. */
    public function register(): void
    {
        $this->app->singleton(Sitemap::class);
    }

    /**
     * Bootstrap after providers are registered: forces HTTPS in production and
     * registers the "login" rate limiter (limit from the security.login_throttle setting).
     */
    public function boot(): void
    {
        // Single password policy for every place a password is set (admin user form,
        // app:create-admin, …): consumers reference Password::defaults() so the
        // requirements live here only.
        Password::defaults(fn () => Password::min(15)->mixedCase()->numbers()->symbols());

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

            // The second, wider limit caps one IP cycling through many emails
            // (credential stuffing), which the email+ip key alone does not stop.
            return [
                Limit::perMinute(max(1, $max))->by($email.'|'.$request->ip()),
                Limit::perMinute(max(1, $max) * self::LOGIN_IP_MULTIPLIER)->by('ip|'.$request->ip()),
            ];
        });

        Event::listen(DiagnosingHealth::class, fn () => $this->diagnoseHealth());
    }

    /**
     * Checks behind GET /up (used by Docker/orchestrator health checks). Any
     * exception turns the response into a 500:
     *  - the database answers a trivial query;
     *  - storage (uploads, backups, temp files) is writable;
     *  - with the database queue, no job has waited longer than 15 minutes —
     *    a stuck or missing worker otherwise goes unnoticed (uploads never finish).
     */
    private function diagnoseHealth(): void
    {
        DB::select('select 1');

        if (! is_writable(storage_path('app'))) {
            throw new \RuntimeException('storage/app is not writable');
        }

        $oldest = $this->app->make(QueueStats::class)->oldestPendingAt();
        if ($oldest !== null && $oldest->lt(now()->subMinutes(15))) {
            throw new \RuntimeException('Queue backlog: a job has waited more than 15 minutes');
        }
    }
}
