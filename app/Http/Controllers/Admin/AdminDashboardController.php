<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use App\Services\BackupService;
use App\Services\QueueStats;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;

/**
 * Admin panel dashboard: summary KPIs and recent activity.
 */
class AdminDashboardController extends Controller
{
    /** A backup older than this is flagged on the dashboard. */
    private const BACKUP_STALE_HOURS = 48;

    /**
     * Assembles the summary (KPIs, distribution by role, activity feed)
     * and renders it on the Dashboard page.
     */
    public function index(Request $request, QueueStats $queueStats, BackupService $backups): Response
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
            'users' => $user->can('users.view') ? $this->usersCard() : null,
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
                'bytes' => (int) Media::sum('size'),
            ] : null,
            'logins' => $user->can('activity-log.view') ? $this->loginsCard() : null,
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
            ? ActivityLog::with(['user', 'impersonator'])
                ->latest()
                ->limit(6)
                ->get()
                ->map(fn (ActivityLog $log) => [
                    'id' => $log->id,
                    'user' => $log->actorName(),
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
            'system' => $this->systemHealth($user, $queueStats, $backups),
        ]);
    }

    /** @return array{value: int, sub: string} */
    private function usersCard(): array
    {
        $blocked = User::where('is_active', false)->count();

        return [
            'value' => User::count(),
            'sub' => $blocked > 0 ? "{$blocked} заблокировано" : 'все активны',
        ];
    }

    /**
     * Successful sign-ins over the last 24 hours, failed attempts in the caption
     * and a 7-day sparkline (rolling 24-hour windows, so no time zone is involved).
     *
     * @return array{value: int, sub: string, spark: list<int>}
     */
    private function loginsCard(): array
    {
        $now = now();

        // One aggregate query: a SUM(CASE …) column per 24-hour window, counted by
        // the database instead of loading a week of rows into PHP.
        $columns = [];
        $bindings = [];
        for ($day = 6; $day >= 0; $day--) {
            // Window "N days ago": (now - (N+1) days, now - N days].
            $columns[] = "SUM(CASE WHEN action = 'login' AND created_at > ? AND created_at <= ? THEN 1 ELSE 0 END) AS d{$day}";
            $bindings[] = $now->copy()->subDays($day + 1);
            $bindings[] = $now->copy()->subDays($day);
        }
        $columns[] = "SUM(CASE WHEN action = 'login_failed' AND created_at >= ? THEN 1 ELSE 0 END) AS failed";
        $bindings[] = $now->copy()->subDay();

        $totals = (array) ActivityLog::query()
            ->toBase()
            ->selectRaw(implode(', ', $columns), $bindings)
            ->whereIn('action', ['login', 'login_failed'])
            ->where('created_at', '>=', $now->copy()->subDays(7))
            ->first();

        $spark = [];
        for ($day = 6; $day >= 0; $day--) {
            $spark[] = (int) ($totals["d{$day}"] ?? 0);
        }
        $failed = (int) ($totals['failed'] ?? 0);

        return [
            'value' => $spark[6],
            'sub' => $failed > 0 ? "{$failed} неудачных за 24 ч" : 'без неудачных попыток',
            'spark' => $spark,
        ];
    }

    /**
     * Queue and backup status for the "System" card; each part is sent only with
     * the permission of the page it links to.
     *
     * @return array{queue: ?array, backup: ?array}
     */
    private function systemHealth(User $user, QueueStats $queueStats, BackupService $backups): array
    {
        $backup = null;
        if ($user->can('backups.view')) {
            $latest = $backups->latest();
            $backup = [
                'latest' => $latest ? ['name' => $latest['name'], 'created_at' => $latest['created_at']] : null,
                'stale' => $latest === null
                    || Carbon::parse($latest['created_at'])->lt(now()->subHours(self::BACKUP_STALE_HOURS)),
            ];
        }

        return [
            'queue' => $user->can('queue.view') ? $queueStats->summary() : null,
            'backup' => $backup,
        ];
    }
}
