<?php

namespace App\Enums;

/** Result of a manual backup request (BackupManager::requestManual()). */
enum BackupRequestStatus
{
    /** Another dump is still being created. */
    case Busy;

    /** The dump job waits for a queue worker. */
    case Queued;

    /** The job ran at once (sync queue) and the dump was created. */
    case Created;

    /** The job ran at once (sync queue) and failed. */
    case Failed;
}
