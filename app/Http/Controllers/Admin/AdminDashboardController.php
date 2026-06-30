<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;

/**
 * Admin panel dashboard: summary KPIs and recent activity.
 */
class AdminDashboardController extends Controller
{
    /**
     * Assembles the summary (KPIs, distribution by role, activity feed)
     * and renders it on the Dashboard page.
     */
    public function index(Request $request): Response
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

        // KPI cards: a primary number + a secondary line with a real metric.
        // These are the template's demo metrics (admin entities) — replace them with
        // your own domain's indicators along with the markup in pages/Dashboard.vue.
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

        // Distribution of users by role (for the horizontal bars).
        $roleDistribution = $roles
            ->map(fn (Role $role) => [
                'name' => $role->name,
                'count' => $role->users_count,
            ])
            ->all();

        // Recent activity feed — the same audit stream that guards the activity
        // log page. The dashboard is open to anyone under auth, so the feed is
        // served only to those who have activity-log.view (otherwise an empty array —
        // Dashboard.vue shows an empty state). Aligned with the "bell" feed gate.
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
