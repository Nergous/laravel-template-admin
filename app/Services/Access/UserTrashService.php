<?php

namespace App\Services\Access;

use App\Models\User;
use App\Support\Selection;
use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * User trash: soft-deleted accounts, restoring and permanent deletion.
 *
 * Whether the actor may touch a specific account (UserPolicy::restore /
 * forceDelete) is checked by the route before the call. Bulk operations ask the
 * policy for each user and skip the forbidden ones. A user whose email has
 * meanwhile been taken by an active user cannot be restored.
 */
class UserTrashService
{
    public function __construct(private readonly Gate $gate) {}

    /**
     * Deleted users, optionally searched by name and email.
     *
     * @return Builder<User>
     */
    public function listQuery(?string $search): Builder
    {
        return $this->search(User::onlyTrashed(), $search);
    }

    /**
     * @throws ValidationException If the email is taken by an active user.
     */
    public function restore(User $user): void
    {
        if ($this->emailTaken($user)) {
            throw ValidationException::withMessages([
                'user' => "Email {$user->email} уже занят активным пользователем",
            ]);
        }

        $user->restore();
    }

    /** Permanently deletes a user from the trash. */
    public function forceDelete(User $user): void
    {
        $user->forceDelete();
    }

    /**
     * Restores the selected users. Those whose email is taken by an active
     * account and those above the actor's level are skipped.
     *
     * @return array{processed: int, skipped: int}
     */
    public function bulkRestore(Selection $selection, User $actor): array
    {
        return $this->eachAllowed($selection, $actor, 'restore', function (User $user) {
            if ($this->emailTaken($user)) {
                return false;
            }

            $user->restore();

            return true;
        });
    }

    /**
     * Permanently deletes the selected users, skipping those above the actor's
     * level.
     *
     * @return array{processed: int, skipped: int}
     */
    public function bulkForceDelete(Selection $selection, User $actor): array
    {
        return $this->eachAllowed($selection, $actor, 'forceDelete', function (User $user) {
            $user->forceDelete();

            return true;
        });
    }

    /**
     * Runs $step for the selected trashed users one model at a time so that
     * restore/forceDelete fire events and reach the log (LogsActivity). Users
     * without the $ability (UserPolicy) are skipped. The trash list is filtered
     * by search only.
     *
     * @param  Closure(User): bool  $step  Returns whether the user was processed.
     * @return array{processed: int, skipped: int}
     */
    private function eachAllowed(Selection $selection, User $actor, string $ability, Closure $step): array
    {
        $gate = $this->gate->forUser($actor);

        return $selection->each(
            User::onlyTrashed()->with(['roles.permissions', 'permissions']),
            fn (Builder $query, array $filters) => $this->search($query, $filters['search'] ?? null),
            fn (User $user) => $gate->allows($ability, $user) && $step($user),
        );
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    private function search(Builder $query, ?string $search): Builder
    {
        return $query->when($search, fn (Builder $query, string $term) => $query->search($term));
    }

    /** Whether the deleted user's email is taken by an active user. */
    private function emailTaken(User $user): bool
    {
        return User::query()
            ->where('email', $user->email)
            ->whereKeyNot($user->getKey())
            ->exists();
    }
}
