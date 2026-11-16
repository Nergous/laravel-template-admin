<?php

namespace App\Services\System\Dashboard;

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\System\ActivityLogService;
use Illuminate\Database\Eloquent\Collection;

/**
 * Admin dashboard blocks. Each is gated by the permission of the section it
 * leads to: the dashboard itself is open to every signed-in user.
 */
class DashboardSummary
{
    /** Entries in the recent activity feed. */
    private const ACTIVITY_LIMIT = 6;

    public function __construct(
        private readonly DashboardStats $stats,
        private readonly DashboardAttention $attention,
        private readonly ActivityLogService $activity,
    ) {}

    /**
     * @return array{stats: array<string, array<string, mixed>>, attention: list<array<string, string>>, recentActivity: Collection<int, ActivityLog>}
     */
    public function for(User $user): array
    {
        return [
            'stats' => $this->stats->for($user),
            'attention' => $this->attention->for($user),
            // The same feed as on the log page, hence the same permission.
            'recentActivity' => $user->can('activity-log.view')
                ? $this->activity->latest(self::ACTIVITY_LIMIT)
                : new Collection,
        ];
    }
}
