<?php

namespace App\Services\Access;

use App\Enums\UserStatus;
use App\Models\User;
use App\Support\Selection;
use Closure;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Operations on active users: creating and changing them together with roles,
 * blocking and deleting. The trash lives in UserTrashService.
 *
 * Whether the actor may touch a specific account (UserPolicy) is checked by the
 * route or form request before the call. Bulk operations ask the policy for
 * each user, skip the forbidden ones and report how many were skipped. Rules
 * about one's own account (cannot delete or block oneself, cannot remove the
 * superadmin role from oneself) are checked here, because the gate lets the
 * superadmin through before the policy.
 *
 * The block reason arrives already trimmed: the global TrimStrings and
 * ConvertEmptyStringsToNull middleware strip edge spaces and empty strings.
 */
class UserService
{
    public function __construct(
        private readonly RbacGuard $rbac,
        private readonly Gate $gate,
    ) {}

    /**
     * Creates a user and assigns roles (in a transaction).
     *
     * @param  array{name: string, email: string, password: string, is_active?: bool, blocked_reason?: string|null}  $data
     * @param  array<int, string>  $roles  Spatie role names.
     */
    public function create(array $data, array $roles): User
    {
        return DB::transaction(function () use ($data, $roles) {
            $active = $data['is_active'] ?? true;

            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'], // hashed by the model's "hashed" cast
                'is_active' => $active,
                'blocked_reason' => $active ? null : ($data['blocked_reason'] ?? null),
            ]);

            $user->syncRoles($roles);

            return $user;
        });
    }

    /**
     * Changes the user and, when $roles is given, their roles (null keeps the
     * current ones). The password changes only when given. An administrator
     * cannot remove the admin role from themselves, and nobody can block themselves.
     *
     * @param  array{name: string, email: string, password?: string|null, is_active?: bool, blocked_reason?: string|null}  $data
     * @param  array<int, string>|null  $roles
     *
     * @throws ValidationException If the actor breaks an own-account protection rule.
     */
    public function update(User $user, array $data, ?array $roles, User $actor): User
    {
        if ($roles !== null && $this->isRemovingOwnSuperadmin($user, $roles, $actor)) {
            throw ValidationException::withMessages([
                'roles' => 'Вы не можете убрать у себя роль '.$this->rbac->superadminRole(),
            ]);
        }

        if ($actor->is($user) && array_key_exists('is_active', $data) && ! $data['is_active']) {
            throw ValidationException::withMessages([
                'is_active' => 'Вы не можете заблокировать самого себя',
            ]);
        }

        return DB::transaction(function () use ($user, $data, $roles) {
            $attributes = [
                'name' => $data['name'],
                'email' => $data['email'],
            ];

            if (filled($data['password'] ?? null)) {
                $attributes['password'] = $data['password'];
            }

            // The reason is kept only while the account is blocked.
            if (array_key_exists('is_active', $data)) {
                $attributes['is_active'] = (bool) $data['is_active'];
                $attributes['blocked_reason'] = $data['is_active']
                    ? null
                    : (array_key_exists('blocked_reason', $data) ? $data['blocked_reason'] : $user->blocked_reason);
            }

            $user->update($attributes);

            if ($roles !== null) {
                $user->syncRoles($roles);
            }

            return $user;
        });
    }

    /**
     * Moves the user to the trash.
     *
     * @throws ValidationException If the actor tries to delete themselves.
     */
    public function delete(User $user, User $actor): void
    {
        $this->ensureNotSelf($user, $actor);

        $user->delete();
    }

    /**
     * Deletes the user permanently, bypassing the trash.
     *
     * @throws ValidationException If the actor tries to delete themselves.
     */
    public function forceDelete(User $user, User $actor): void
    {
        $this->ensureNotSelf($user, $actor);

        $user->forceDelete();
    }

    /**
     * Moves the selected users to the trash, skipping the actor's own account
     * and those the actor cannot manage.
     *
     * @return array{processed: int, skipped: int}
     */
    public function bulkDelete(Selection $selection, User $actor): array
    {
        return $this->eachAllowed($selection, $actor, 'delete', fn (User $user) => $user->delete());
    }

    /**
     * Deletes the selected users permanently with the same skips as bulkDelete().
     *
     * @return array{processed: int, skipped: int}
     */
    public function bulkForceDelete(Selection $selection, User $actor): array
    {
        return $this->eachAllowed($selection, $actor, 'forceDelete', fn (User $user) => $user->forceDelete());
    }

    /**
     * Blocks or unblocks the selected users. The actor's own account is always
     * skipped: blocking it is forbidden and unblocking it is pointless.
     *
     * @return array{processed: int, skipped: int}
     */
    public function bulkSetActive(Selection $selection, bool $active, User $actor, ?string $reason = null): array
    {
        $reason = $active ? null : $reason;

        return $this->eachAllowed($selection, $actor, 'update', fn (User $user) => $user->update([
            'is_active' => $active,
            'blocked_reason' => $reason,
        ]));
    }

    /**
     * The filtered user list query, shared by the list, the export and the
     * "all matching" bulk actions so they always agree.
     * role — role names, any of them matches (User::scopeFilterByRole);
     * status — a UserStatus value or null for all.
     *
     * @param  array{search?: string|null, role?: string|list<string>|null, status?: string|null}  $filters
     * @return Builder<User>
     */
    public function listQuery(array $filters): Builder
    {
        return $this->filter(User::query(), $filters);
    }

    /**
     * @param  Builder<User>  $query
     * @param  array<string, mixed>  $filters  See listQuery()
     * @return Builder<User>
     */
    private function filter(Builder $query, array $filters): Builder
    {
        $status = UserStatus::tryFrom((string) ($filters['status'] ?? ''));

        return $query
            ->search($filters['search'] ?? null)
            ->filterByRole($filters['role'] ?? null)
            ->when($status !== null, fn (Builder $query) => $query->where('is_active', $status === UserStatus::Active));
    }

    /**
     * Runs $action for the selected users one model at a time (so LogsActivity
     * records each change), skipping the actor's own account and those they lack
     * the $ability for (UserPolicy).
     *
     * @param  Closure(User): mixed  $action
     * @return array{processed: int, skipped: int}
     */
    private function eachAllowed(Selection $selection, User $actor, string $ability, Closure $action): array
    {
        $gate = $this->gate->forUser($actor);

        return $selection->each(
            User::query()->with(['roles.permissions', 'permissions']),
            $this->filter(...),
            function (User $user) use ($actor, $gate, $ability, $action) {
                if ($actor->is($user) || $gate->denies($ability, $user)) {
                    return false;
                }

                $action($user);

                return true;
            },
        );
    }

    /** @throws ValidationException If the actor deletes themselves. */
    private function ensureNotSelf(User $user, User $actor): void
    {
        if ($actor->is($user)) {
            throw ValidationException::withMessages([
                'user' => 'Вы не можете удалить самого себя',
            ]);
        }
    }

    /**
     * Prevents the superadmin from removing the superadmin role
     * (config('rbac.superadmin_role')) from themselves.
     *
     * @param  array<int, string>  $newRoles
     */
    private function isRemovingOwnSuperadmin(User $user, array $newRoles, User $actor): bool
    {
        $superadmin = $this->rbac->superadminRole();

        return $actor->is($user)
            && $user->hasRole($superadmin)
            && ! in_array($superadmin, $newRoles, true);
    }
}
