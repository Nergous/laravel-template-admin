<?php

namespace App\Http\Controllers\Admin\System;

use App\Http\Controllers\Controller;
use App\Http\Requests\System\QueueFilterRequest;
use App\Services\System\FailedJobs;
use App\Services\System\QueueJobs;
use App\Services\System\QueueStats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Queue overview: counters (QueueStats), one list of queued jobs
 * (database driver) and failed jobs (QueueJobs), retrying and deleting failed ones
 * (FailedJobs).
 *
 * Viewing requires queue.view; retrying and deleting failed jobs —
 * queue.manage.
 */
class QueueController extends Controller
{
    public function __construct(
        private readonly QueueStats $stats,
        private readonly QueueJobs $jobs,
        private readonly FailedJobs $failed,
    ) {}

    public function index(QueueFilterRequest $request): Response
    {
        $status = $request->status();

        return Inertia::render('Queue/Index', [
            'summary' => $this->stats->summary(),
            // The same rule as /up?full=1: a ready job waited too long (no live worker).
            'stalled' => $this->stats->isStalled(),
            'stalledAfterMinutes' => QueueStats::STALLED_AFTER_MINUTES,
            'counts' => $this->stats->statusCounts(),
            'status' => $status?->value,
            ...$this->stats->connection(),
            'jobs' => $this->jobs->page($status, $request->page())->withPath($request->url()),
        ]);
    }

    public function retry(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($this->failed->retry($uuid, $request->user()), 404);

        return back()->with('success', 'Задача возвращена в очередь');
    }

    public function retryAll(Request $request): RedirectResponse
    {
        $count = $this->failed->retryAll($request->user());

        return $count === 0
            ? back()->with('info', 'Упавших задач нет')
            : back()->with('success', "Возвращено в очередь задач: {$count}");
    }

    public function destroy(Request $request, string $uuid): RedirectResponse
    {
        abort_unless($this->failed->forget($uuid, $request->user()), 404);

        return back()->with('success', 'Задача удалена');
    }

    public function flush(Request $request): RedirectResponse
    {
        $count = $this->failed->flush($request->user());

        return $count === 0
            ? back()->with('info', 'Упавших задач нет')
            : back()->with('success', "Удалено упавших задач: {$count}");
    }
}
