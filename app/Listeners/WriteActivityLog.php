<?php

namespace App\Listeners;

use App\Events\ActivityOccurred;
use App\Services\System\ActivityRecorder;

/**
 * Writes the ActivityOccurred event to the activity log. Runs synchronously,
 * so for events without an explicit author the request or job context is still
 * available.
 */
class WriteActivityLog
{
    public function __construct(private readonly ActivityRecorder $recorder) {}

    public function handle(ActivityOccurred $event): void
    {
        $this->recorder->record($event);
    }
}
