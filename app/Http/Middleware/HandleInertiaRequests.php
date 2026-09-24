<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use App\Providers\SettingsServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;
use Spatie\Permission\Models\Permission;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root Blade template for the current section.
     *
     * The admin panel and the public part are separate Inertia applications: each has its own
     * bundle (resources/js/<section>/app.ts) and its own root-view. Currently only
     * the admin panel is implemented. When the public part appears — create
     * resources/views/public.blade.php + resources/js/public/app.ts and
     * uncomment the branch below.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     */
    public function rootView(Request $request): string
    {
        // return $request->is('admin', 'admin/*') ? 'admin' : 'public';
        return 'admin';
    }

    /**
     * Asset version — for invalidating the Inertia client cache on deploy.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props passed into all Inertia responses by default: the user
     * with roles and permissions, counts for the sidebar badges and flash messages.
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

            'appName' => config('app.name'),

            // Display time zone (settings → general.timezone); dates are stored in UTC.
            'timezone' => fn () => SettingsServiceProvider::displayTimezone(),

            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(),
                    'must_change_password' => (bool) $user->must_change_password,
                ] : null,

                // Names of all the user's permissions — for conditional rendering in Vue
                // (can('users.view')). The server still checks independently.
                'can' => $user ? $user->getAllPermissions()->pluck('name')->all() : [],
            ],

            // Record counts for the sidebar badges. The raw aggregates are global
            // (do not depend on the user) and are cached for 30s; we only return
            // the sections for which the user has the *.view permission — otherwise
            // a badge would reveal a number where there is no access. The bell count
            // is personal: unread actions of other users (see ActivityLog::unreadFor).
            'counts' => $user ? function () use ($user) {
                $all = Cache::remember('admin.sidebar-counts', 30, fn () => [
                    'users' => User::count(),
                    'roles' => Role::count(),
                    'permissions' => Permission::count(),
                    'media' => Media::count(),
                ]);

                return [
                    'users' => $user->can('users.view') ? $all['users'] : null,
                    'roles' => $user->can('roles.view') ? $all['roles'] : null,
                    'permissions' => $user->can('permissions.view') ? $all['permissions'] : null,
                    'media' => $user->can('media.view') ? $all['media'] : null,
                    'recentActivity' => $user->can('activity-log.view')
                        ? ActivityLog::unreadFor($user)->count()
                        : null,
                ];
            } : null,

            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'warning' => fn () => $request->session()->get('warning'),
                'info' => fn () => $request->session()->get('info'),
            ],
        ];
    }
}
