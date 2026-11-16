<?php

namespace App\Http\Middleware;

use App\Services\System\Settings;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Copies the session lifetime from the database settings into the web request
 * config (security.session_lifetime → session.lifetime). Runs in the web group
 * before StartSession so the session already gets the configured lifetime.
 *
 * The app name is not copied into the config: HandleInertiaRequests and the
 * admin.blade template read it directly from App\Services\System\Settings.
 * The general.timezone setting is not applied here: it is only the display
 * time zone (App\Services\System\DisplayTime).
 */
class ApplyRuntimeSettings
{
    public function __construct(
        private readonly Settings $settings,
        private readonly Config $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->config->set([
            'session.lifetime' => max(1, (int) $this->settings->value('security', 'session_lifetime')),
        ]);

        return $next($request);
    }
}
