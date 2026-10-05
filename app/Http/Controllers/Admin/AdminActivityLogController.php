<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\LinksActivitySubjects;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Providers\SettingsServiceProvider;
use App\Support\FilterValues;
use App\Support\TableExport;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Viewing, exporting, and clearing the activity (audit) log in the admin panel.
 *
 * Reads (index/export) are gated by the activity-log.view permission;
 * clearing the log up to a selected date (clear) is gated by a separate
 * activity-log.delete permission. Log entries are written elsewhere (see the
 * LogsActivity trait and ActivityLog::record()); the notification bell lives in
 * NotificationController.
 *
 * Date filters are entered in the display time zone (settings → general.timezone)
 * and converted to the storage zone (UTC) before querying.
 */
class AdminActivityLogController extends Controller
{
    use LinksActivitySubjects;

    /**
     * Renders the paginated (30 per page) activity log on the ActivityLog/Index page.
     *
     * Filters: action, subject_type (+ subject_id for one entity), user_id,
     * impersonator_id, date_from, date_to (inclusive days). Action, subject
     * type and user take several comma-separated values (FilterValues): the
     * values of one filter combine with OR, the filters with AND.
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
            'exportColumns' => TableExport::options($this->exportColumns()),
        ]);
    }

    /**
     * Exports the filtered log as CSV or XLSX with the chosen columns (see
     * TableExport). Streams rows in chunks so a large log does not load into
     * memory; lazy() keeps eager loading (cursor() would run a query per row).
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->validatedFilters($request);
        $timezone = SettingsServiceProvider::displayTimezone();
        $query = $this->filteredQuery($filters)->with(['user', 'impersonator']);

        return TableExport::fromRequest($request, $this->exportColumns())->download(
            $query->lazy(500),
            'activity-log-'.now($timezone)->format('Y-m-d_His'),
            'Журнал действий',
        );
    }

    /**
     * Columns of the log export, in file order.
     *
     * @return array<string, array{0: string, 1: \Closure(ActivityLog): (string|null)}>
     */
    private function exportColumns(): array
    {
        $timezone = SettingsServiceProvider::displayTimezone();

        return [
            'created_at' => ['Дата', fn (ActivityLog $log) => $log->created_at?->timezone($timezone)->format('Y-m-d H:i:s')],
            'actor' => ['Пользователь', fn (ActivityLog $log) => $log->actorName()],
            'action' => ['Действие', fn (ActivityLog $log) => $log->actionLabel()],
            'subject_type' => ['Тип', fn (ActivityLog $log) => $log->subjectTypeLabel()],
            'subject' => ['Объект', fn (ActivityLog $log) => $log->subject_label],
            'changes' => ['Изменения', fn (ActivityLog $log) => $log->changes ? json_encode($log->changes, JSON_UNESCAPED_UNICODE) : null],
        ];
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
     * Filters for the list and the export. Multi-value filters stay
     * comma-separated strings, as in the address.
     *
     * @return array{action: ?string, subject_type: ?string, subject_id: ?string, user_id: ?string, impersonator_id: ?string, date_from: ?string, date_to: ?string}
     */
    private function validatedFilters(Request $request): array
    {
        $data = $request->validate([
            'subject_id' => ['nullable', 'integer', 'min:1'],
            'impersonator_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $list = fn (array $values) => $values === [] ? null : implode(',', $values);
        $subjectTypes = FilterValues::strings($request->input('subject_type'));

        return [
            'action' => $list(FilterValues::strings($request->input('action'))),
            'subject_type' => $list($subjectTypes),
            // An entity id only makes sense together with exactly one type.
            'subject_id' => isset($data['subject_id']) && count($subjectTypes) === 1 ? (string) $data['subject_id'] : null,
            'user_id' => $list(FilterValues::ids($request->input('user_id'))),
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
            ->when($filters['action'], fn (Builder $q, string $actions) => $q->whereIn('action', FilterValues::strings($actions)))
            ->when($filters['subject_type'], fn (Builder $q, string $types) => $q->whereIn('subject_type', FilterValues::strings($types)))
            ->when($filters['subject_id'], fn (Builder $q, string $id) => $q->where('subject_id', (int) $id))
            ->when($filters['user_id'], fn (Builder $q, string $ids) => $q->whereIn('user_id', FilterValues::ids($ids)))
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
}
