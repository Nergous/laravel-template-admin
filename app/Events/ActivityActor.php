<?php

namespace App\Events;

use App\Models\User;

/**
 * The action author a service passed to the log explicitly. The wrapper tells
 * "the author is the system" (user = null) from "no author passed" (no wrapper):
 * in the latter case ActivityRecorder takes the author from the request or job context.
 */
final readonly class ActivityActor
{
    public function __construct(public ?User $user) {}
}
