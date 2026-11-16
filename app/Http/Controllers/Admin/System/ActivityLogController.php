<?php

namespace App\Http\Controllers\Admin\System;

use App\Exports\ActivityLogExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\System\ActivityLogFilterRequest;
use App\Http\Requests\System\ClearActivityLogRequest;
use App\Http\Requests\System\ExportActivityLogRequest;
use App\Http\Resources\System\ActivityLogResource;
use App\Http\Responses\TableDownload;
use App\Models\ActivityLog;
use App\Services\System\ActivityLogLabels;
use App\Services\System\ActivityLogService;
use App\Services\System\ActivitySubjectLinks;
use App\Services\System\DisplayTime;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Viewing, exporting and clearing the activity (audit) log in the admin panel.
 *
 * Reading (index/export) requires activity-log.view; clearing the log up to a
 * chosen date (clear) requires the separate activity-log.delete permission.
 * Queries live in ActivityLogService.
 */
class ActivityLogController extends Controller
{
    public function __construct(
        private readonly ActivityLogService $log,
        private readonly ActivityLogExport $export,
        private readonly ActivitySubjectLinks $links,
        private readonly ActivityLogLabels $labels,
        private readonly DisplayTime $time,
    ) {}

    /**
     * The activity log, 30 entries per page.
     *
     * Filters: action, subject_type (+ subject_id for a single subject), user_id,
     * date_from, date_to (see ActivityLogService::filtered()).
     */
    public function index(ActivityLogFilterRequest $request): Response
    {
        $filters = $request->filters();
        $logs = $this->log->paginate($filters)->withQueryString();

        $urls = $this->links->for($logs->getCollection(), $request->user());

        $logs->through(fn (ActivityLog $log) => (new ActivityLogResource($log, $this->labels, $urls[$log->id] ?? null))->resolve($request));

        return Inertia::render('ActivityLog/Index', [
            'logs' => $logs,
            'filters' => $request->formFilters(),
            'subjectLabel' => $this->log->subjectLabel($filters),
            'fieldLabels' => $this->labels->fieldLabels(),
            'actions' => $this->labels->actionOptions(except: $this->log->unusedLegacyActions()),
            'subjectTypes' => $this->labels->subjectTypeOptions(),
            'actors' => $this->log->actorOptions(),
            'exportColumns' => $this->export->options(),
        ]);
    }

    /** Exports the filtered log as CSV or XLSX with the chosen columns (ActivityLogExport). */
    public function export(ExportActivityLogRequest $request): TableDownload
    {
        return $this->export->download(
            $this->log->filtered($request->filters()),
            $request->exportFormat(),
            $request->exportColumns(),
        );
    }

    /**
     * Clears the log: deletes every entry older than the chosen date (the start
     * of that day in the display time zone).
     */
    public function clear(ClearActivityLogRequest $request): RedirectResponse
    {
        $deleted = $this->log->clearBefore($request->cutoff($this->time), (string) $request->validated('before'), $request->user());

        return redirect()
            ->back()
            ->with('success', "Журнал очищен. Удалено записей: {$deleted}");
    }
}
