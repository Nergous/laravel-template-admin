<?php

namespace App\Services\System;

use App\Events\ActivityOccurred;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Writes activity log entries (on the ActivityOccurred event). Besides the
 * subject it stores snapshots of the subject and author names, so "who did
 * what" survives the permanent deletion of either. A failed log write must not
 * break the user's action, so a database error goes to report() and is swallowed.
 *
 * Author: explicit from the event (ActivityOccurred::by), otherwise overridden
 * via actingAs(), otherwise the current request's user. The last two are needed
 * only by model events (LogsActivity): a model does not know who changes it.
 *
 * Scoped: the actingAs() author override lives within one request or job.
 */
#[Scoped]
class ActivityRecorder
{
    /**
     * Overridden author for model events in code without a signed-in user
     * (queue jobs): see actingAs(). $hasActorOverride tells an explicit null
     * ("the system") from "no override".
     */
    private ?User $actorOverride = null;

    private bool $hasActorOverride = false;

    public function __construct(private readonly AuthFactory $auth) {}

    public function record(ActivityOccurred $event): void
    {
        $actor = $this->actor($event);
        $actorId = $actor?->getKey();

        // The author may have been force-deleted during the request or job: referencing them would break the foreign key.
        if ($actorId !== null && ! User::withTrashed()->whereKey($actorId)->exists()) {
            $actorId = null;
        }

        $subject = $event->subject;

        try {
            ActivityLog::create([
                'user_id' => $actorId,
                'action' => $event->action,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'subject_label' => $event->label ?? ($subject ? $this->labelFor($subject) : null),
                'actor_label' => $actor?->name,
                'changes' => $event->changes,
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            report($e);
        }
    }

    /**
     * Runs $callback so that model events inside it have $actor as the author.
     * Queue workers have no signed-in user, so without this a file uploaded by
     * an administrator would be logged as a system action.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function actingAs(?User $actor, callable $callback): mixed
    {
        $previous = [$this->hasActorOverride, $this->actorOverride];
        $this->hasActorOverride = true;
        $this->actorOverride = $actor;

        try {
            return $callback();
        } finally {
            [$this->hasActorOverride, $this->actorOverride] = $previous;
        }
    }

    private function actor(ActivityOccurred $event): ?User
    {
        if ($event->actor !== null) {
            return $event->actor->user;
        }

        return $this->hasActorOverride ? $this->actorOverride : $this->auth->guard()->user();
    }

    /** Subject label: title → name → original_name → filename → id. */
    private function labelFor(Model $subject): string
    {
        return $subject->title
            ?? $subject->name
            ?? $subject->original_name
            ?? $subject->filename
            ?? (string) $subject->getKey();
    }
}
