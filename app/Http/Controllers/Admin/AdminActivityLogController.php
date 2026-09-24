<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use App\Providers\SettingsServiceProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role as SpatieRole;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Viewing, exporting, and clearing the activity (audit) log in the admin panel.
 *
 * Reads (index/export/recent) are gated by the activity-log.view permission;
 * clearing the log up to a selected date (clear) is gated by a separate
 * activity-log.delete permission. Log entries are written elsewhere (see the
 * LogsActivity trait and ActivityLog::record()).
 *
 * Date filters are entered in the display time zone (settings → general.timezone)
 * and converted to the storage zone (UTC) before querying.
 */
class AdminActivityLogController extends Controller
{
    /**
     * Renders the paginated (30 per page) activity log on the ActivityLog/Index page.
     *
     * Filters: action, subject_type, user_id, date_from, date_to (inclusive days).
     */
    public function index(Request $request): Response
    {
        $filters = $this->validatedFilters($request);

        $logs = $this->filteredQuery($filters)
            ->with('user')
            ->paginate(30)
            ->withQueryString();

        $urls = $this->subjectUrls($logs->getCollection());

        $logs->through(fn (ActivityLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'actionLabel' => $log->actionLabel(),
            // Live author name → actor_label snapshot (survives force-delete) → "Система".
            'actor' => $log->user?->name ?? $log->actor_label ?? 'Система',
            'subject' => $log->subject_label ?: $log->subjectTypeLabel(),
            'subjectType' => $log->subjectTypeLabel(),
            'subjectUrl' => $urls[$log->id] ?? null,
            'changesCount' => is_array($log->changes) ? count($log->changes) : 0,
            'changes' => $log->changes,
            'createdAt' => optional($log->created_at)->toIso8601String(),
        ]);

        return Inertia::render('ActivityLog/Index', [
            'logs' => $logs,
            'filters' => $filters,
            'actions' => $this->actionOptions(),
            'subjectTypes' => collect(config('audit.subjects'))
                ->map(fn (string $key, string $class) => [
                    'value' => $class,
                    'label' => __('activity.subjects.'.$key),
                ])
                ->values(),
            'actors' => User::withTrashed()
                ->whereIn('id', ActivityLog::query()->whereNotNull('user_id')->distinct()->select('user_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $u) => ['value' => (string) $u->id, 'label' => $u->name])
                ->values(),
        ]);
    }

    /**
     * Exports the filtered log as CSV (semicolon-separated, UTF-8 with BOM for
     * Excel). Streams rows so a large log does not load into memory.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->validatedFilters($request);
        $timezone = SettingsServiceProvider::displayTimezone();
        $query = $this->filteredQuery($filters)->with('user');

        return response()->streamDownload(function () use ($query, $timezone) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Дата', 'Пользователь', 'Действие', 'Тип', 'Объект', 'Изменения'], ';');

            foreach ($query->cursor() as $log) {
                /** @var ActivityLog $log */
                fputcsv($out, array_map($this->csvCell(...), [
                    $log->created_at?->timezone($timezone)->format('Y-m-d H:i:s') ?? '',
                    $log->user?->name ?? $log->actor_label ?? 'Система',
                    $log->actionLabel(),
                    $log->subjectTypeLabel(),
                    $log->subject_label ?? '',
                    $log->changes ? json_encode($log->changes, JSON_UNESCAPED_UNICODE) : '',
                ]), ';');
            }

            fclose($out);
        }, 'activity-log-'.now($timezone)->format('Y-m-d_His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Clears the log: deletes all entries older than the selected date (start of
     * that day in the display time zone). The clearing itself is written to the
     * log afterwards, so removing history always leaves a trace.
     *
     * @param  Request  $request  before (date, not in the future) — the cutoff date.
     */
    public function clear(Request $request): RedirectResponse
    {
        $timezone = SettingsServiceProvider::displayTimezone();

        $validated = $request->validate([
            'before' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now($timezone)->toDateString()],
        ], [
            'before.required' => 'Укажите дату, до которой очистить журнал',
            'before.date_format' => 'Некорректная дата',
            'before.before_or_equal' => 'Дата не может быть в будущем',
        ]);

        $before = Carbon::parse($validated['before'], $timezone)->startOfDay()->utc();
        $deleted = ActivityLog::where('created_at', '<', $before)->delete();

        ActivityLog::record(null, 'cleared', [
            'before' => [null, $validated['before']],
            'deleted' => [null, $deleted],
        ]);

        return redirect()
            ->back()
            ->with('success', "Журнал очищен. Удалено записей: {$deleted}");
    }

    /**
     * JSON feed for the "bell": the unread counter and the latest 10 actions of
     * other users over the past week, each marked as read or unread.
     */
    public function recent(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $seenAt = $user->notifications_seen_at ?? now()->subDay();

        $items = ActivityLog::forBell($user)
            ->with('user')
            ->latest('created_at')
            ->limit(10)
            ->get();

        $urls = $this->subjectUrls($items);

        return response()->json([
            'count' => ActivityLog::unreadFor($user)->count(),
            'items' => $items->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'user' => $log->user?->name ?? $log->actor_label ?? 'Система',
                'action' => $log->actionLabel(),
                'subject' => $log->subject_label ?? $log->subjectTypeLabel(),
                'time' => $log->created_at->diffForHumans(),
                'iso_time' => $log->created_at->toIso8601String(),
                'unread' => $log->created_at->greaterThan($seenAt),
                'url' => $urls[$log->id] ?? route('admin.activity-log.index'),
            ]),
        ]);
    }

    /** Marks the bell as read: everything up to now stops counting as unread. */
    public function markSeen(Request $request): JsonResponse
    {
        $request->user()->updateSilently(['notifications_seen_at' => now()]);

        return response()->json(['count' => 0]);
    }

    /**
     * @return array{action: ?string, subject_type: ?string, user_id: ?string, date_from: ?string, date_to: ?string}
     */
    private function validatedFilters(Request $request): array
    {
        $data = $request->validate([
            'action' => ['nullable', 'string', 'max:64'],
            'subject_type' => ['nullable', 'string', 'max:255'],
            'user_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return [
            'action' => $data['action'] ?? null,
            'subject_type' => $data['subject_type'] ?? null,
            'user_id' => isset($data['user_id']) ? (string) $data['user_id'] : null,
            'date_from' => $data['date_from'] ?? null,
            'date_to' => $data['date_to'] ?? null,
        ];
    }

    /**
     * @param  array{action: ?string, subject_type: ?string, user_id: ?string, date_from: ?string, date_to: ?string}  $filters
     */
    private function filteredQuery(array $filters): Builder
    {
        $timezone = SettingsServiceProvider::displayTimezone();

        return ActivityLog::query()
            ->latest('created_at')
            ->latest('id')
            ->when($filters['action'], fn (Builder $q, string $action) => $q->where('action', $action))
            ->when($filters['subject_type'], fn (Builder $q, string $type) => $q->where('subject_type', $type))
            ->when($filters['user_id'], fn (Builder $q, string $id) => $q->where('user_id', (int) $id))
            ->when($filters['date_from'], fn (Builder $q, string $date) => $q->where(
                'created_at', '>=', Carbon::parse($date, $timezone)->startOfDay()->utc(),
            ))
            ->when($filters['date_to'], fn (Builder $q, string $date) => $q->where(
                'created_at', '<', Carbon::parse($date, $timezone)->addDay()->startOfDay()->utc(),
            ));
    }

    /** @return list<array{value: string, label: string}> */
    private function actionOptions(): array
    {
        return collect(__('activity.actions'))
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
            ->reject(fn (array $option) => $option['value'] === 'duplicated'
                && ! ActivityLog::where('action', 'duplicated')->exists())
            ->values()
            ->all();
    }

    /**
     * Links from log entries to the entities they describe. Entities that no
     * longer exist (deleted users, removed roles) get no link.
     *
     * @param  Collection<int, ActivityLog>  $logs
     * @return array<int, string> log id → URL
     */
    private function subjectUrls(Collection $logs): array
    {
        $idsOf = fn (string $type) => $logs->where('subject_type', $type)->pluck('subject_id')->filter()->unique()->all();

        $users = User::whereIn('id', $idsOf(User::class))->pluck('id')->flip();
        $roles = Role::whereIn('id', $idsOf(SpatieRole::class))->pluck('id')->flip();
        $media = Media::whereIn('id', $idsOf(Media::class))->pluck('id')->flip();

        $urls = [];
        foreach ($logs as $log) {
            $url = match (true) {
                $log->subject_type === User::class && $users->has($log->subject_id) => route('admin.users.show', $log->subject_id),
                $log->subject_type === SpatieRole::class && $roles->has($log->subject_id) => route('admin.roles.show', $log->subject_id),
                $log->subject_type === Media::class && $media->has($log->subject_id) => route('admin.media.index', ['search' => $log->subject_label]),
                $log->subject_type === Permission::class => route('admin.permissions.index'),
                default => null,
            };

            if ($url !== null) {
                $urls[$log->id] = $url;
            }
        }

        return $urls;
    }

    /** Neutralizes spreadsheet formulas in exported cells (CSV injection). */
    private function csvCell(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
