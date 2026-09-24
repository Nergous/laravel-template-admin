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
        $user = $request->user();

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
        // Each card is sent only with the matching *.view permission, like the
        // sidebar badges: the dashboard is open to every signed-in user.
        $stats = array_filter([
            'users' => $user->can('users.view') ? [
                'value' => User::count(),
                'sub' => '',
            ] : null,
            'roles' => $user->can('roles.view') ? [
                'value' => $roles->count(),
                'sub' => "{$rolesAssigned} с пользователями",
            ] : null,
            'permissions' => $user->can('permissions.view') ? [
                'value' => $permissionsTotal,
                'sub' => "{$resourceCount} ресурсов",
            ] : null,
            'media' => $user->can('media.view') ? [
                'value' => Media::count(),
                'sub' => "{$mediaCategories} категорий",
            ] : null,
        ]);

        // Distribution of users by role (for the horizontal bars) — needs roles.view.
        $roleDistribution = $user->can('roles.view')
            ? $roles
                ->map(fn (Role $role) => [
                    'name' => $role->name,
                    'count' => $role->users_count,
                ])
                ->all()
            : [];

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
                    'user' => $log->user?->name ?? $log->actor_label,
                    'action' => $log->action,
                    'action_label' => $log->actionLabel(),
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
