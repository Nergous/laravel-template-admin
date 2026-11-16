<?php

namespace App\Services\System;

use App\Enums\QueueJobStatus;
use Illuminate\Queue\Failed\CountableFailedJobProvider;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Support\Carbon;

/**
 * Queue state for the dashboard, the /admin/queue page and the /up check:
 * counters and the stalled-queue flag. The job list lives in QueueJobs.
 *
 * Queued jobs (waiting, delayed, running) are visible only with the database
 * driver (the jobs table); for sync and other drivers they are returned as null
 * ("unavailable"). Failed jobs are read through the configured failer, so any
 * failed job driver works.
 */
class QueueStats
{
    /** A ready job waiting longer than this means the worker is stuck or not running. */
    public const STALLED_AFTER_MINUTES = 15;

    public function __construct(
        private readonly QueueTable $table,
        private readonly FailedJobProviderInterface $failer,
    ) {}

    /**
     * The default queue connection and its driver.
     *
     * @return array{connection: string, driver: string|null}
     */
    public function connection(): array
    {
        return $this->table->connection();
    }

    /** @return array{pending: int|null, oldest_pending_at: string|null, failed: int} */
    public function summary(): array
    {
        $database = $this->table->isDatabase();

        return [
            'pending' => $database ? $this->table->waiting()->count() : null,
            'oldest_pending_at' => $database ? $this->oldestPendingAt()?->toIso8601String() : null,
            'failed' => $this->failedCount(),
        ];
    }

    /**
     * Since when the oldest ready job has been waiting (delayed jobs count from
     * available_at). Null when nothing waits or the driver is not database.
     */
    public function oldestPendingAt(): ?Carbon
    {
        if (! $this->table->isDatabase()) {
            return null;
        }

        $oldest = $this->table->waiting()
            ->where('available_at', '<=', now()->getTimestamp())
            ->min('available_at');

        return $oldest === null ? null : Carbon::createFromTimestampUTC((int) $oldest);
    }

    /**
     * Whether a ready job has waited longer than STALLED_AFTER_MINUTES (database
     * queue only). Used by /up?full=1 and the queue page.
     */
    public function isStalled(): bool
    {
        $oldest = $this->oldestPendingAt();

        return $oldest !== null && $oldest->lt(now()->subMinutes(self::STALLED_AFTER_MINUTES));
    }

    public function failedCount(): int
    {
        return $this->failer instanceof CountableFailedJobProvider
            ? $this->failer->count()
            : count($this->failer->all());
    }

    /**
     * Job counts per status. Queued statuses are null when the driver is not database.
     *
     * @return array{running: int|null, pending: int|null, delayed: int|null, failed: int}
     */
    public function statusCounts(): array
    {
        $database = $this->table->isDatabase();
        $counts = [];

        foreach (QueueJobStatus::queued() as $status) {
            $counts[$status->value] = $database ? $this->table->queued($status)->count() : null;
        }

        return $counts + ['failed' => $this->failedCount()];
    }
}
