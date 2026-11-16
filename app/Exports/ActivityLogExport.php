<?php

namespace App\Exports;

use App\Enums\ExportFormat;
use App\Http\Responses\TableDownload;
use App\Models\ActivityLog;
use App\Services\System\ActivityLogLabels;
use App\Services\System\DisplayTime;
use App\Support\Export\TableExport;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Activity log export (CSV or XLSX, see TableExport): columns and file name.
 * Rows are streamed in chunks so a large log is never loaded into memory;
 * lazy() keeps eager loading (cursor() would run a query per row).
 */
class ActivityLogExport
{
    public function __construct(
        private readonly ActivityLogLabels $labels,
        private readonly DisplayTime $time,
    ) {}

    /** @return list<array{key: string, label: string}> Columns to choose from on the log page. */
    public function options(): array
    {
        return TableExport::options($this->columns());
    }

    /**
     * @param  Builder<ActivityLog>  $query  Filtered log query
     * @param  list<string>  $columns  Keys of the chosen columns; empty — all
     */
    public function download(Builder $query, ExportFormat $format, array $columns): TableDownload
    {
        return new TableDownload(
            TableExport::make($this->columns(), $format, $this->time, $columns),
            $query->with('user')->lazy(500),
            'activity-log',
            'Журнал действий',
        );
    }

    /**
     * Columns in file order.
     *
     * @return array<string, array{0: string, 1: Closure(ActivityLog): (string|null)}>
     */
    private function columns(): array
    {
        $timezone = $this->time->zone();

        return [
            'created_at' => ['Дата', fn (ActivityLog $log) => $log->created_at?->timezone($timezone)->format('Y-m-d H:i:s')],
            'actor' => ['Пользователь', fn (ActivityLog $log) => $this->labels->actor($log)],
            'action' => ['Действие', fn (ActivityLog $log) => $this->labels->action($log)],
            'subject_type' => ['Тип', fn (ActivityLog $log) => $this->labels->subjectType($log)],
            'subject' => ['Объект', fn (ActivityLog $log) => $log->subject_label],
            'changes' => ['Изменения', fn (ActivityLog $log) => $log->changes ? json_encode($log->changes, JSON_UNESCAPED_UNICODE) : null],
        ];
    }
}
