<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Viewing and clearing the activity (audit) log in the admin panel.
 *
 * Reads (index/recent) are gated by the activity-log.view permission; clearing the
 * log up to a selected date (clear) is gated by a separate activity-log.delete
 * permission. Log entries are written elsewhere (see the LogsActivity trait); here
 * they are read and bulk-cleared.
 */
class AdminActivityLogController extends Controller
{
    /**
     * Renders the paginated (30 per page) activity log on the ActivityLog/Index page.
     *
     * Supports optional action and subject_type filters; entries are sorted by
     * creation date (newest first).
     *
     * @param  Request  $request  The action and subject_type parameters are taken into account.
     */
    public function index(Request $request): Response
    {
        $query = ActivityLog::with('user')->latest('created_at');

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->subject_type);
        }

        $logs = $query->paginate(30)
            ->withQueryString()
            ->through(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actionLabel' => $log->actionLabel(),
                // Live author name → actor_label snapshot (survives force-delete) → "Система".
                'actor' => $log->user?->name ?? $log->actor_label ?? 'Система',
                'subject' => $log->subject_label ?: $log->subjectTypeLabel(),
                'subjectType' => $log->subjectTypeLabel(),
                'changesCount' => is_array($log->changes) ? count($log->changes) : 0,
                'changes' => $log->changes,
                'createdAt' => optional($log->created_at)->toIso8601String(),
            ]);

        return Inertia::render('ActivityLog/Index', [
            'logs' => $logs,
            'filters' => $request->only('action', 'subject_type'),
        ]);
    }

    /**
     * Clears the log: deletes all entries older than the selected date (start of day).
     *
     * The `before` date is required — it means "clear events up to …". Deletion is
     * a single bulk DELETE without hydration/events (the audit log has no delete
     * events, and clearing should be cheap; cf. ActivityLog::prunable()).
     *
     * @param  Request  $request  before (date) — the cutoff date.
     */
    public function clear(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'before' => ['required', 'date'],
        ], [
            'before.required' => 'Укажите дату, до которой очистить журнал',
            'before.date' => 'Некорректная дата',
        ]);

        $before = Carbon::parse($validated['before'])->startOfDay();
        $deleted = ActivityLog::where('created_at', '<', $before)->delete();

        return redirect()
            ->back()
            ->with('success', "Журнал очищен. Удалено записей: {$deleted}");
    }

    /**
     * JSON feed for the sidebar "bell": a counter and the last 10 actions over
     * the past 24 hours. Gated by activity-log.view (see routes/web.php) — the feed
     * serves the same audit stream as the index() page, so it requires the same
     * permission, not just authentication.
     */
    public function recent(): JsonResponse
    {
        $count = ActivityLog::recent()->count();

        $items = ActivityLog::with('user')
            ->recent()
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'user' => $log->user?->name ?? $log->actor_label ?? '—',
                'action' => $log->actionLabel(),
                'subject' => $log->subject_label ?? $log->subjectTypeLabel(),
                'time' => $log->created_at->diffForHumans(),
                'iso_time' => $log->created_at->toIso8601String(),
                'url' => route('admin.activity-log.index'),
            ]);

        return response()->json([
            'count' => $count,
            'items' => $items,
        ]);
    }
}
