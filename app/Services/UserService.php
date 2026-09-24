<?php

namespace App\Services;

use App\Models\User;
use App\Support\RbacGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Domain operations on users: create/update with roles, soft delete and trash
 * operations.
 */
class UserService
{
    /**
     * Creates a user and assigns them roles (in a transaction).
     *
     * @param  array{name: string, email: string, password: string}  $data
     * @param  array<int, string>  $roles  Spatie role names.
     */
    public function create(array $data, array $roles): User
    {
        return DB::transaction(function () use ($data, $roles) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
            ]);

            $user->syncRoles($roles);

            return $user;
        });
    }

    /**
     * Updates a user and their roles. The password changes only if provided;
     * an admin cannot remove the admin role from themselves.
     *
     * @param  array{name: string, email: string, password?: string|null}  $data
     * @param  array<int, string>  $roles
     *
     * @throws ValidationException If the actor removes the admin role from themselves.
     */
    public function update(User $user, array $data, array $roles, ?User $actor): User
    {
        if ($this->isRemovingOwnAdmin($user, $roles, $actor)) {
            throw ValidationException::withMessages([
                'roles' => 'Вы не можете убрать у себя роль admin',
            ]);
        }

        return DB::transaction(function () use ($user, $data, $roles) {
            $user->update([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => filled($data['password'] ?? null)
                    ? Hash::make($data['password'])
                    : $user->password,
            ]);

            $user->syncRoles($roles);

            return $user;
        });
    }

    /**
     * Soft-deletes a user (you cannot delete yourself).
     *
     * @throws ValidationException If the actor tries to delete themselves.
     */
    public function delete(User $user, ?User $actor): void
    {
        if ($actor !== null && $actor->id === $user->id) {
            throw ValidationException::withMessages([
                'user' => 'Вы не можете удалить самого себя',
            ]);
        }

        $user->delete();
    }

    /**
     * Moves selected users to trash, excluding the acting user.
     *
     * @param  array<int, int>  $ids
     */
    public function bulkDelete(array $ids, ?User $actor): int
    {
        return $this->applyDelete(
            User::query()->whereIn('id', $ids),
            $actor,
        );
    }

    /** Moves every user matching the current list filters to trash. */
    public function bulkDeleteAll(?string $search, ?string $role, ?User $actor): int
    {
        return $this->applyDelete(
            User::query()
                ->search($search)
                ->filterByRole($role),
            $actor,
        );
    }

    /**
     * Restores a user from the trash.
     *
     * @param  int  $id  Identifier of the user in the trash
     */
    public function restore(int $id): void
    {
        User::onlyTrashed()->findOrFail($id)->restore();
    }

    /**
     * Permanently deletes a user from the trash (along with files/relations).
     *
     * @param  int  $id  Identifier of the user in the trash
     */
    public function forceDelete(int $id): void
    {
        User::onlyTrashed()->findOrFail($id)->forceDelete();
    }

    /**
     * Bulk restore from the trash.
     *
     * @param  array<int, int>  $ids
     * @return int How many users were processed.
     */
    public function bulkRestore(array $ids): int
    {
        return $this->applyToTrashed(
            User::onlyTrashed()->whereIn('id', $ids),
            fn (User $user) => $user->restore(),
        );
    }

    /** Restores every trashed user matching the current list filter. */
    public function bulkRestoreAll(?string $search): int
    {
        return $this->applyToTrashed(
            User::onlyTrashed()->search($search),
            fn (User $user) => $user->restore(),
        );
    }

    /**
     * Bulk permanent delete from the trash.
     *
     * @param  array<int, int>  $ids
     * @return int How many users were processed.
     */
    public function bulkForceDelete(array $ids): int
    {
        return $this->applyToTrashed(
            User::onlyTrashed()->whereIn('id', $ids),
            fn (User $user) => $user->forceDelete(),
        );
    }

    /** Permanently deletes every trashed user matching the current list filter. */
    public function bulkForceDeleteAll(?string $search): int
    {
        return $this->applyToTrashed(
            User::onlyTrashed()->search($search),
            fn (User $user) => $user->forceDelete(),
        );
    }

    /**
     * Applies an action to each user from a trash query.
     * Iterates over models (not a mass-update) so that restore/forceDelete
     * fire events and end up in the activity log (LogsActivity).
     *
     * @param  callable(User): void  $action
     * @return int How many users were processed.
     */
    private function applyToTrashed(Builder $query, callable $action): int
    {
        $count = 0;

        $query->lazyById()->each(function (User $user) use ($action, &$count) {
            $action($user);
            $count++;
        });

        return $count;
    }

    /** Soft-deletes a query in chunks while preserving model events. */
    private function applyDelete(Builder $query, ?User $actor): int
    {
        if ($actor !== null) {
            $query->where('id', '!=', $actor->id);
        }

        $count = 0;

        $query->lazyById()->each(function (User $user) use (&$count) {
            $user->delete();
            $count++;
        });

        return $count;
    }

    /**
     * Guards against a superadmin removing the superadmin role from themselves
     * (config('rbac.superadmin_role')).
     *
     * @param  array<int, string>  $newRoles
     */
    private function isRemovingOwnAdmin(User $user, array $newRoles, ?User $actor): bool
    {
        $superadmin = RbacGuard::superadminRole();

        return $actor !== null
            && $actor->id === $user->id
            && $user->hasRole($superadmin)
            && ! in_array($superadmin, $newRoles, true);
    }
}
