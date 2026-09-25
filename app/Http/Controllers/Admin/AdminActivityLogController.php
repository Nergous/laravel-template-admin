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
use Illuminate\Validation\Rule;
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
     * Filters: action, subject_type (+ subject_id for one entity), user_id,
     * date_from, date_to (inclusive days).
     */
    public function index(Request $request): Response
    {
        $filters = $this->validatedFilters($request);

        $logs = $this->filteredQuery($filters)
            ->with(['user', 'impersonator'])
            ->paginate(30)
            ->withQueryString();

        $urls = $this->subjectUrls($logs->getCollection());

        $logs->through(fn (ActivityLog $log) => [
            'id' => $log->id,
            'action' => $log->action,
            'actionLabel' => $log->actionLabel(),
            // Live author name → actor_label snapshot (survives force-delete) → "Система".
            'actor' => $log->actorName(),
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
            'subjectLabel' => $this->subjectLabel($filters),
            'fieldLabels' => __('activity.fields'),
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
            'impersonators' => User::withTrashed()
                ->whereIn('id', ActivityLog::query()->whereNotNull('impersonator_id')->distinct()->select('impersonator_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $u) => ['value' => (string) $u->id, 'label' => $u->name])
                ->values(),
        ]);
    }

    /**
     * Exports the filtered log as CSV (semicolon-separated, UTF-8 with BOM for
     * Excel). Streams rows in chunks so a large log does not load into memory;
     * lazy() keeps eager loading (cursor() would run a query per row).
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->validatedFilters($request);
        $timezone = SettingsServiceProvider::displayTimezone();
        $query = $this->filteredQuery($filters)->with(['user', 'impersonator']);

        return response()->streamDownload(function () use ($query, $timezone) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Дата', 'Пользователь', 'Действие', 'Тип', 'Объект', 'Изменения'], ';');

            foreach ($query->lazy(500) as $log) {
                /** @var ActivityLog $log */
                fputcsv($out, array_map($this->csvCell(...), [
                    $log->created_at?->timezone($timezone)->format('Y-m-d H:i:s') ?? '',
                    $log->actorName(),
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
     * @return array{action: ?string, subject_type: ?string, subject_id: ?string, user_id: ?string, impersonator_id: ?string, date_from: ?string, date_to: ?string}
     */
    private function validatedFilters(Request $request): array
    {
        $data = $request->validate([
            'action' => ['nullable', 'string', 'max:64'],
            'subject_type' => ['nullable', 'string', 'max:255'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'user_id' => ['nullable', 'integer'],
            'impersonator_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return [
            'action' => $data['action'] ?? null,
            'subject_type' => $data['subject_type'] ?? null,
            // An entity id only makes sense together with its type.
            'subject_id' => isset($data['subject_id'], $data['subject_type']) ? (string) $data['subject_id'] : null,
            'user_id' => isset($data['user_id']) ? (string) $data['user_id'] : null,
            'impersonator_id' => isset($data['impersonator_id']) ? (string) $data['impersonator_id'] : null,
            'date_from' => $data['date_from'] ?? null,
            'date_to' => $data['date_to'] ?? null,
        ];
    }

    /**
     * @param  array{action: ?string, subject_type: ?string, subject_id: ?string, user_id: ?string, impersonator_id: ?string, date_from: ?string, date_to: ?string}  $filters
     */
    private function filteredQuery(array $filters): Builder
    {
        $timezone = SettingsServiceProvider::displayTimezone();

        return ActivityLog::query()
            ->latest('created_at')
            ->latest('id')
            ->when($filters['action'], fn (Builder $q, string $action) => $q->where('action', $action))
            ->when($filters['subject_type'], fn (Builder $q, string $type) => $q->where('subject_type', $type))
            ->when($filters['subject_id'], fn (Builder $q, string $id) => $q->where('subject_id', (int) $id))
            ->when($filters['user_id'], fn (Builder $q, string $id) => $q->where('user_id', (int) $id))
            ->when($filters['impersonator_id'], fn (Builder $q, string $id) => $q->where('impersonator_id', (int) $id))
            ->when($filters['date_from'], fn (Builder $q, string $date) => $q->where(
                'created_at', '>=', Carbon::parse($date, $timezone)->startOfDay()->utc(),
            ))
            ->when($filters['date_to'], fn (Builder $q, string $date) => $q->where(
                'created_at', '<', Carbon::parse($date, $timezone)->addDay()->startOfDay()->utc(),
            ));
    }

    /**
     * The name of the entity selected by subject_type + subject_id, taken from
     * its latest log entry (it may no longer exist).
     *
     * @param  array{subject_type: ?string, subject_id: ?string}  $filters
     */
    private function subjectLabel(array $filters): ?string
    {
        if ($filters['subject_id'] === null) {
            return null;
        }

        $label = ActivityLog::where('subject_type', $filters['subject_type'])
            ->where('subject_id', (int) $filters['subject_id'])
            ->whereNotNull('subject_label')
            ->latest('id')
            ->value('subject_label');

        return $label ?: '#'.$filters['subject_id'];
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
