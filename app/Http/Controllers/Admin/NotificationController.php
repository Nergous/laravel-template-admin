<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\LinksActivitySubjects;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The notification bell: the latest actions of other users (an activity log
 * feed), the unread counter, the read marker and muted categories. Gated by
 * activity-log.view on the routes.
 */
class NotificationController extends Controller
{
    use LinksActivitySubjects;

    /**
     * JSON feed for the "bell": the unread counter and the latest 10 actions of
     * other users over the past week, each marked as read or unread. Consecutive
     * failed sign-ins for the same account collapse into one item with a count.
     * Also returns the user's muted categories for the settings panel.
     */
    public function recent(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $seenAt = $user->notifications_seen_at ?? now()->subDay();

        $rows = ActivityLog::forBell($user)
            ->with(['user', 'impersonator'])
            ->latest('created_at')
            ->latest('id')
            ->limit(50)
            ->get();

        /** @var list<array{log: ActivityLog, repeat: int}> $groups */
        $groups = [];
        foreach ($rows as $log) {
            $last = array_key_last($groups);
            if ($last !== null && $log->action === 'login_failed'
                && $groups[$last]['log']->action === 'login_failed'
                && $groups[$last]['log']->subject_label === $log->subject_label) {
                $groups[$last]['repeat']++;

                continue;
            }
            if (count($groups) === 10) {
                break;
            }
            $groups[] = ['log' => $log, 'repeat' => 1];
        }

        $items = collect($groups)->pluck('log');
        $urls = $this->subjectUrls($items);

        return response()->json([
            'count' => ActivityLog::unreadCountFor($user),
            'mutes' => array_values(array_intersect(ActivityLog::NOTIFICATION_CATEGORIES, $user->notification_mutes ?? [])),
            'items' => collect($groups)->map(fn (array $group) => [
                ...$this->bellItem($group['log'], $seenAt, $urls),
                'repeat' => $group['repeat'],
            ])->values(),
        ]);
    }

    /** Unread bell counter for the background refresh in the layout. */
    public function count(Request $request): JsonResponse
    {
        return response()->json(['count' => ActivityLog::unreadCountFor($request->user())]);
    }

    /**
     * Saves the bell categories the user muted. Stored silently: it is a personal
     * preference, not an audited change.
     */
    public function preferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mutes' => ['present', 'array'],
            'mutes.*' => ['string', Rule::in(ActivityLog::NOTIFICATION_CATEGORIES)],
        ]);

        $mutes = array_values(array_unique($data['mutes']));
        $request->user()->updateSilently(['notification_mutes' => $mutes === [] ? null : $mutes]);

        return response()->json([
            'mutes' => $mutes,
            'count' => ActivityLog::unreadCountFor($request->user()),
        ]);
    }

    /** Marks the bell as read: everything up to now stops counting as unread. */
    public function markSeen(Request $request): JsonResponse
    {
        $request->user()->updateSilently(['notifications_seen_at' => now()]);

        return response()->json(['count' => 0]);
    }

    /**
     * @param  array<int, string>  $urls
     * @return array<string, mixed>
     */
    private function bellItem(ActivityLog $log, \DateTimeInterface $seenAt, array $urls): array
    {
        return [
            'id' => $log->id,
            'user' => $log->actorName(),
            'action' => $log->actionLabel(),
            'category' => $log->category(),
            'subject' => $log->subject_label ?: $log->subjectTypeLabel(),
            'time' => $log->created_at->diffForHumans(),
            'iso_time' => $log->created_at->toIso8601String(),
            'unread' => $log->created_at->greaterThan($seenAt),
            'url' => $urls[$log->id] ?? route('admin.activity-log.index'),
        ];
    }
}
