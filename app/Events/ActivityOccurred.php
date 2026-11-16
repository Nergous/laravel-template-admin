<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Event: an action happened that must be written to the activity log. The
 * entry is created by App\Listeners\WriteActivityLog.
 *
 * Services dispatch the event via by() and pass the author explicitly (null —
 * the system). Only model events (App\Models\Concerns\LogsActivity) dispatch it
 * without an author: there the author comes from the request or job context
 * (see App\Services\System\ActivityRecorder).
 */
class ActivityOccurred
{
    use Dispatchable;

    /**
     * @param  Model|null  $subject  The entity acted upon; null for actions without
     *                               one (a failed login with an unknown email,
     *                               clearing the log)
     * @param  string  $action  Action name (created, updated, deleted, restored …)
     * @param  array<string, array{0: mixed, 1: mixed}>|null  $changes  Diff of changed fields: field → [old, new]
     * @param  string|null  $label  Subject label override (the entity name by default)
     * @param  ActivityActor|null  $actor  Explicit author; null — take it from the context
     */
    public function __construct(
        public readonly ?Model $subject,
        public readonly string $action,
        public readonly ?array $changes = null,
        public readonly ?string $label = null,
        public readonly ?ActivityActor $actor = null,
    ) {}

    /**
     * An action with a known author: this is how services write to the log.
     *
     * @param  User|null  $actor  Who performed the action; null — the system
     * @param  array<string, array{0: mixed, 1: mixed}>|null  $changes
     */
    public static function by(?User $actor, ?Model $subject, string $action, ?array $changes = null, ?string $label = null): void
    {
        static::dispatch($subject, $action, $changes, $label, new ActivityActor($actor));
    }
}
