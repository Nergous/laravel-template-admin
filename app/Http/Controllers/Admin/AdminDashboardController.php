<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Permission;

/**
 * Дашборд админ-панели: сводные KPI и последняя активность.
 */
class AdminDashboardController extends Controller
{
    /**
     * Собирает сводку (KPI, распределение по ролям, ленту действий)
     * и отдаёт её на страницу Dashboard.
     */
    public function index(Request $request): \Inertia\Response
    {
        $roles = Role::withCount('users')
            ->orderByDesc('users_count')
            ->get();
        $rolesAssigned = $roles->where('users_count', '>', 0)->count();

        $permissionNames = Permission::query()->pluck('name');
        $permissionsTotal = $permissionNames->count();
        $resourceCount = $permissionNames
            ->map(fn (string $name) => explode('.', $name, 2)[0])
            ->unique()
            ->count();

        $mediaCategories = Media::query()->distinct()->count('type');

        // KPI-карточки: основное число + вторичная строка с реальной метрикой.
        // Это демо-метрики шаблона (сущности админки) — замените на показатели
        // своего домена вместе с разметкой в pages/Dashboard.vue.
        $stats = [
            'users' => [
                'value' => User::count(),
                'sub' => '',
            ],
            'roles' => [
                'value' => $roles->count(),
                'sub' => "{$rolesAssigned} с пользователями",
            ],
            'permissions' => [
                'value' => $permissionsTotal,
                'sub' => "{$resourceCount} ресурсов",
            ],
            'media' => [
                'value' => Media::count(),
                'sub' => "{$mediaCategories} категорий",
            ],
        ];

        // Распределение пользователей по ролям (для горизонтальных баров).
        $roleDistribution = $roles
            ->map(fn (Role $role) => [
                'name' => $role->name,
                'count' => $role->users_count,
            ])
            ->all();

        // Лента последних действий — та же аудит-лента, что защищает страницу
        // журнала. Дашборд открыт всем под auth, поэтому ленту отдаём только
        // тем, у кого есть activity-log.view (иначе пустой массив — Dashboard.vue
        // покажет пустое состояние). Согласовано с гейтом фида «колокольчика».
        $recentActivity = $request->user()->can('activity-log.view')
            ? ActivityLog::with('user')
                ->latest()
                ->limit(6)
                ->get()
                ->map(fn (ActivityLog $log) => [
                    'id' => $log->id,
                    'user' => $log->user?->name,
                    'action' => $log->action,
                    'subject_label' => $log->subject_label,
                    'subject_type' => $log->subjectTypeLabel(),
                    'changes_count' => is_array($log->changes) ? count($log->changes) : 0,
                    'created_at' => $log->created_at?->toIso8601String(),
                    'created_human' => $log->created_at?->diffForHumans(),
                ])
                ->all()
            : [];

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'roleDistribution' => $roleDistribution,
            'recentActivity' => $recentActivity,
        ]);
    }
}
