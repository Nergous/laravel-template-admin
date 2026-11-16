<?php

namespace App\Enums;

/** Job status on the queue page; the case order is the display order. */
enum QueueJobStatus: string
{
    /** A worker is running the job. */
    case Running = 'running';

    /** The job is ready and waits for a worker. */
    case Pending = 'pending';

    /** The job is delayed until available_at. */
    case Delayed = 'delayed';

    /** The job failed and sits in the failed jobs table. */
    case Failed = 'failed';

    /**
     * Statuses of jobs in the queue table (not failed ones).
     *
     * @return list<self>
     */
    public static function queued(): array
    {
        return [self::Running, self::Pending, self::Delayed];
    }
}
