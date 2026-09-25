<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\QueueStats;
use Illuminate\Http\RedirectResponse;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Queue overview: waiting jobs (database driver) and failed jobs.
 *
 * Viewing is gated by queue.view; retrying and deleting failed jobs by
 * queue.manage. Retries go through queue:retry so the job is pushed back to its
 * original connection and queue exactly as the framework does it.
 */
class AdminQueueController extends Controller
{
    public function __construct(
        private readonly QueueStats $stats,
        private readonly FailedJobProviderInterface $failer,
    ) {}

    public function index(): Response
    {
        $connection = (string) config('queue.default');

        return Inertia::render('Queue/Index', [
            'summary' => $this->stats->summary(),
            'connection' => $connection,
            'driver' => config("queue.connections.{$connection}.driver"),
            'failedJobs' => $this->stats->failedJobs(20),
        ]);
    }

    public function retry(string $uuid): RedirectResponse
    {
        $job = $this->failer->find($uuid);
        abort_if($job === null, 404);

        Artisan::call('queue:retry', ['id' => [$uuid]]);
        ActivityLog::record(null, 'job_retried', null, QueueStats::jobName($job));

        return back()->with('success', 'Задача возвращена в очередь');
    }

    public function retryAll(): RedirectResponse
    {
        $count = $this->stats->failedCount();
        if ($count === 0) {
            return back()->with('info', 'Упавших задач нет');
        }

        Artisan::call('queue:retry', ['id' => ['all']]);
        ActivityLog::record(null, 'job_retried', null, "Все упавшие задачи ({$count})");

        return back()->with('success', "Возвращено в очередь задач: {$count}");
    }

    public function destroy(string $uuid): RedirectResponse
    {
        $job = $this->failer->find($uuid);
        abort_if($job === null, 404);

        $this->failer->forget($uuid);
        ActivityLog::record(null, 'job_deleted', null, QueueStats::jobName($job));

        return back()->with('success', 'Задача удалена');
    }

    public function flush(): RedirectResponse
    {
        $count = $this->stats->failedCount();
        if ($count === 0) {
            return back()->with('info', 'Упавших задач нет');
        }

        $this->failer->flush();
        ActivityLog::record(null, 'job_deleted', null, "Все упавшие задачи ({$count})");

        return back()->with('success', "Удалено упавших задач: {$count}");
    }
}
