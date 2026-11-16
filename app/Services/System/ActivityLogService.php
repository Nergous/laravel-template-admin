<?php

namespace App\Services\System;

use App\Events\ActivityOccurred;
use App\Models\ActivityLog;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

/**
 * Activity (audit) log queries: the filtered selection, filter data and
 * clearing. Entries are written by ActivityRecorder on the ActivityOccurred
 * event; screen labels come from ActivityLogLabels.
 *
 * Filter dates are entered in the display time zone (general.timezone setting)
 * and converted to the storage zone (UTC) before querying.
 */
class ActivityLogService
{
    /**
     * Actions the application no longer writes. The filter shows them only
     * while the log still has such entries.
     */
    private const LEGACY_ACTIONS = ['duplicated'];

    public function __construct(private readonly DisplayTime $time) {}

    /**
     * A log page with the author of each entry.
     *
     * @param  array{action: list<string>, subject_type: list<string>, subject_id: ?int, user_id: list<int>, date_from: ?string, date_to: ?string}  $filters
     * @return LengthAwarePaginator<int, ActivityLog>
     */
    public function paginate(array $filters, int $perPage = 30): LengthAwarePaginator
    {
        return $this->filtered($filters)->with('user')->paginate($perPage);
    }

    /**
     * Entries matching the filters, newest first. Action, subject type and
     * author take lists of values (parsed by the request): values of one filter
     * are combined with OR, filters with AND; an empty list does not restrict;
     * dates include the whole day.
     *
     * @param  array{action: list<string>, subject_type: list<string>, subject_id: ?int, user_id: list<int>, date_from: ?string, date_to: ?string}  $filters
     * @return Builder<ActivityLog>
     */
    public function filtered(array $filters): Builder
    {
        $timezone = $this->time->zone();

        return ActivityLog::query()
            ->newestFirst()
            ->when($filters['action'], fn (Builder $q, array $actions) => $q->whereIn('action', $actions))
            ->when($filters['subject_type'], fn (Builder $q, array $types) => $q->whereIn('subject_type', $types))
            ->when($filters['subject_id'], fn (Builder $q, int $id) => $q->where('subject_id', $id))
            ->when($filters['user_id'], fn (Builder $q, array $ids) => $q->whereIn('user_id', $ids))
            ->when($filters['date_from'], fn (Builder $q, string $date) => $q->where(
                'created_at', '>=', Carbon::parse($date, $timezone)->startOfDay()->utc(),
            ))
            ->when($filters['date_to'], fn (Builder $q, string $date) => $q->where(
                'created_at', '<', Carbon::parse($date, $timezone)->addDay()->startOfDay()->utc(),
            ));
    }

    /**
     * Name of the subject selected via subject_type + subject_id, from its
     * latest log entry (the subject itself may be gone).
     *
     * @param  array{subject_type: list<string>, subject_id: ?int}  $filters
     */
    public function subjectLabel(array $filters): ?string
    {
        if ($filters['subject_id'] === null) {
            return null;
        }

        $label = ActivityLog::whereIn('subject_type', $filters['subject_type'])
            ->where('subject_id', $filters['subject_id'])
            ->whereNotNull('subject_label')
            ->latest('id')
            ->value('subject_label');

        return $label ?: '#'.$filters['subject_id'];
    }

    /**
     * Legacy actions with no entries in the log: the filter does not offer them.
     *
     * @return list<string>
     */
    public function unusedLegacyActions(): array
    {
        $present = ActivityLog::query()
            ->whereIn('action', self::LEGACY_ACTIONS)
            ->distinct()
            ->pluck('action')
            ->all();

        return array_values(array_diff(self::LEGACY_ACTIONS, $present));
    }

    /**
     * Authors of log entries (including deleted users) for the author filter.
     *
     * @return list<array{value: string, label: string}>
     */
    public function actorOptions(): array
    {
        return User::withTrashed()
            ->whereIn('id', ActivityLog::query()->whereNotNull('user_id')->distinct()->select('user_id'))
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (User $user) => ['value' => (string) $user->id, 'label' => $user->name])
            ->values()
            ->all();
    }

    /**
     * Deletes every entry older than $cutoff and returns their count. The
     * clearing itself is logged after the deletion, so erasing history always
     * leaves a trace.
     *
     * @param  string  $before  The date as the user entered it — for the log entry
     */
    public function clearBefore(CarbonInterface $cutoff, string $before, User $actor): int
    {
        $deleted = ActivityLog::where('created_at', '<', $cutoff)->delete();

        ActivityOccurred::by($actor, null, 'cleared', [
            'before' => [null, $before],
            'deleted' => [null, $deleted],
        ]);

        return $deleted;
    }

    /**
     * Latest entries where the user is the author or the subject.
     *
     * @return Collection<int, ActivityLog>
     */
    public function latestInvolving(User $user, int $limit): Collection
    {
        return ActivityLog::query()
            ->with('user')
            ->involving($user)
            ->newestFirst()
            ->limit($limit)
            ->get();
    }

    /**
     * Newest entries with authors (the dashboard feed).
     *
     * @return Collection<int, ActivityLog>
     */
    public function latest(int $limit): Collection
    {
        return ActivityLog::query()
            ->with('user')
            ->newestFirst()
            ->limit($limit)
            ->get();
    }
}
