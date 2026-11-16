<?php

namespace App\Services\System;

use App\Events\ActivityOccurred;
use App\Models\User;
use App\Support\JobPayload;
use Illuminate\Contracts\Console\Kernel as Artisan;
use Illuminate\Queue\Failed\FailedJobProviderInterface;

/**
 * Retrying and deleting failed queue jobs; every action is logged.
 * Retries go through queue:retry: the job returns to its original connection
 * and queue exactly as the framework does it.
 */
class FailedJobs
{
    public function __construct(
        private readonly FailedJobProviderInterface $failer,
        private readonly QueueStats $stats,
        private readonly Artisan $artisan,
    ) {}

    /** Pushes one failed job back to the queue; false — no such job. */
    public function retry(string $uuid, User $actor): bool
    {
        $job = $this->failer->find($uuid);
        if ($job === null) {
            return false;
        }

        $this->artisan->call('queue:retry', ['id' => [$uuid]]);
        ActivityOccurred::by($actor, null, 'job_retried', null, JobPayload::displayName($job));

        return true;
    }

    /** Pushes every failed job back to the queue and returns their count. */
    public function retryAll(User $actor): int
    {
        $count = $this->stats->failedCount();
        if ($count > 0) {
            $this->artisan->call('queue:retry', ['id' => ['all']]);
            ActivityOccurred::by($actor, null, 'job_retried', null, "Все упавшие задачи ({$count})");
        }

        return $count;
    }

    /** Deletes one failed job; false — no such job. */
    public function forget(string $uuid, User $actor): bool
    {
        $job = $this->failer->find($uuid);
        if ($job === null) {
            return false;
        }

        $this->failer->forget($uuid);
        ActivityOccurred::by($actor, null, 'job_deleted', null, JobPayload::displayName($job));

        return true;
    }

    /** Deletes every failed job and returns their count. */
    public function flush(User $actor): int
    {
        $count = $this->stats->failedCount();
        if ($count > 0) {
            $this->failer->flush();
            ActivityOccurred::by($actor, null, 'job_deleted', null, "Все упавшие задачи ({$count})");
        }

        return $count;
    }
}
