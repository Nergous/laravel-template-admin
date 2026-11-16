<?php

namespace App\Listeners;

use App\Services\System\QueueStats;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * GET /up checks. Any exception turns the response into a 500.
 *
 * Plain /up is the liveness check for the Docker health check the scheduler
 * container waits for: it only checks that the database answers a simple
 * query and storage (uploads, backups, temporary files) is writable.
 * The queue must not fail it — otherwise one long job would mark the web
 * container unhealthy and keep an optional queue worker that depends on it from starting.
 *
 * /up?full=1 also fails when, with the database queue, a job has waited
 * longer than QueueStats::STALLED_AFTER_MINUTES (the worker is stuck or not running) —
 * for external monitoring. The queue page in the admin panel shows the same delay.
 */
class DiagnoseHealth
{
    public function __construct(
        private readonly QueueStats $queue,
        private readonly Request $request,
    ) {}

    public function handle(DiagnosingHealth $event): void
    {
        DB::select('select 1');

        if (! is_writable(storage_path('app'))) {
            throw new RuntimeException('storage/app is not writable');
        }

        if ($this->request->boolean('full') && $this->queue->isStalled()) {
            throw new RuntimeException('Queue backlog: a job has waited more than '.QueueStats::STALLED_AFTER_MINUTES.' minutes');
        }
    }
}
