<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Middleware;
use Spatie\Permission\Models\Permission;

class HandleInertiaRequests extends Middleware
{
    /**
     * Корневой Blade-шаблон под текущий раздел.
     *
     * Админка и публичная часть — отдельные Inertia-приложения: у каждого свой
     * бандл (resources/js/<section>/app.js) и свой root-view. Сейчас реализована
     * только админка. Когда появится публичная часть — создать
     * resources/views/public.blade.php + resources/js/public/app.js и
     * раскомментировать ветку ниже.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     */
    public function rootView(Request $request): string
    {
        // return $request->is('admin', 'admin/*') ? 'admin' : 'public';
        return 'admin';
    }

    /**
     * Версия ассетов — для инвалидации клиентского кэша Inertia при деплое.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Пропсы, передаваемые во все Inertia-ответы по умолчанию: пользователь
     * с ролями и правами, счётчики для бейджей сайдбара и flash-сообщения.
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
            'bot' => [
                'enabled' => config('bot.enabled'),
            ],

            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->getRoleNames(),
                ] : null,

                // Имена всех прав пользователя — для условного рендера во Vue
                // (can('users.view')). Сервер всё равно проверяет независимо.
                'can' => $user ? $user->getAllPermissions()->pluck('name')->all() : [],
            ],

            // Счётчики записей для бейджей в сайдбаре и колокольчике. Сырые
            // агрегаты глобальны (не зависят от пользователя) и кэшируются на 30с;
            // отдаём же только разделы, на которые у пользователя есть право
            // *.view — иначе бейдж раскрыл бы число там, куда нет доступа.
            'counts' => $user ? function () use ($user) {
                $all = Cache::remember('admin.sidebar-counts', 30, fn () => [
                    'users' => User::count(),
                    'roles' => Role::count(),
                    'permissions' => Permission::count(),
                    'media' => Media::count(),
                    'recentActivity' => ActivityLog::recent()->count(),
                ]);

                return [
                    'users' => $user->can('users.view') ? $all['users'] : null,
                    'roles' => $user->can('roles.view') ? $all['roles'] : null,
                    'permissions' => $user->can('permissions.view') ? $all['permissions'] : null,
                    'media' => $user->can('media.view') ? $all['media'] : null,
                    'recentActivity' => $user->can('activity-log.view') ? $all['recentActivity'] : null,
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
