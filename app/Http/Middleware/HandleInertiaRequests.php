<?php

namespace App\Http\Middleware;

use App\Services\Access\RbacGuard;
use App\Services\System\AccentColor;
use App\Services\System\DisplayTime;
use App\Services\System\Settings;
use App\Services\System\SidebarCounts;
use Illuminate\Auth\SessionGuard;
use Illuminate\Container\Attributes\Auth;
use Illuminate\Container\Attributes\Config;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Inertia handling and shared props of the admin panel. Attached to the /admin
 * route group only (routes/web.php), not to the whole web group.
 */
class HandleInertiaRequests extends Middleware
{
    public function __construct(
        private readonly AccentColor $accentColor,
        private readonly RbacGuard $rbac,
        private readonly SidebarCounts $sidebarCounts,
        private readonly Settings $settings,
        private readonly DisplayTime $time,
        #[Config('app.name')] private readonly string $defaultAppName,
        #[Auth('web')] private readonly SessionGuard $guard,
    ) {}

    /**
     * Root Blade template of the Inertia admin panel.
     *
     * Only the admin panel is an Inertia application. A public site is
     * rendered on the server with Blade (layout resources/views/public.blade.php)
     * and never passes through this middleware.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     */
    public function rootView(Request $request): string
    {
        return 'admin';
    }

    /**
     * Props passed into all Inertia responses by default: the user with roles
     * and permissions, counts for the sidebar badges and flash messages.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),

            // Name from the settings (general.app_name); empty falls back to config/app.php.
            'appName' => fn () => $this->settings->value('general', 'app_name') ?: $this->defaultAppName,

            // Brand icon in the sidebar: the favicon from the settings (media library),
            // otherwise the static one — the same image as in the browser tab.
            'brandIcon' => fn () => $this->settings->value('general', 'favicon') ?: '/favicon.svg',

            // Accent color preset (settings → appearance.accent); the client syncs
            // <html data-accent> after the settings are saved.
            'accent' => fn () => $this->accentColor->current()->value,

            // Display time zone (settings → general.timezone); dates are stored in UTC.
            'timezone' => fn () => $this->time->zone(),

            // Idle session lifetime in minutes (security.session_lifetime) — drives
            // the "session is about to expire" warning in the layout. Null with a
            // "remember me" cookie: an expired session then signs back in silently.
            'sessionLifetime' => fn () => $user && ! $request->hasCookie($this->guard->getRecallerName())
                ? (int) config('session.lifetime')
                : null,

            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(),
                ] : null,

                // Names of all the user's permissions — for conditional rendering in Vue
                // (can('users.view')). The server still checks access independently.
                'can' => $user ? $this->rbac->effectivePermissions($user) : [],
            ],

            // Record counts for the sidebar badges — only for sections the user can access.
            'counts' => $user ? fn () => $this->sidebarCounts->for($user) : null,

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
            ],
        ];
    }
}
