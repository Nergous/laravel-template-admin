<?php

namespace App\Services;

use App\Models\User;
use App\Support\RbacGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Domain operations on users: create/update with roles, blocking, soft delete
 * and trash operations.
 *
 * Every operation on another account goes through RbacGuard::canManageUser():
 * a non-admin cannot touch an administrator or anyone with permissions above
 * their own. Bulk operations skip such users and report how many were skipped.
 */
class UserService
{
    /**
     * Creates a user and assigns them roles (in a transaction).
     *
     * @param  array{name: string, email: string, password: string, is_active?: bool, must_change_password?: bool}  $data
     * @param  array<int, string>  $roles  Spatie role names.
     */
    public function create(array $data, array $roles): User
    {
        return DB::transaction(function () use ($data, $roles) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => Hash::make($data['password']),
                'is_active' => $data['is_active'] ?? true,
                'must_change_password' => $data['must_change_password'] ?? false,
            ]);

            $user->syncRoles($roles);

            return $user;
        });
    }

    /**
     * Updates a user and their roles. The password changes only if provided;
     * an admin cannot remove the admin role from themselves, and nobody can
     * block their own account.
     *
     * @param  array{name: string, email: string, password?: string|null, is_active?: bool, must_change_password?: bool}  $data
     * @param  array<int, string>  $roles
     *
     * @throws ValidationException If the actor may not manage the user or breaks a self-protection rule.
     */
    public function update(User $user, array $data, array $roles, ?User $actor): User
    {
        $this->ensureCanManage($user, $actor);

        if ($this->isRemovingOwnAdmin($user, $roles, $actor)) {
            throw ValidationException::withMessages([
                'roles' => 'Вы не можете убрать у себя роль '.RbacGuard::superadminRole(),
            ]);
        }

        if ($actor?->is($user) && array_key_exists('is_active', $data) && ! $data['is_active']) {
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
                $attributes['password'] = Hash::make($data['password']);
            }

            foreach (['is_active', 'must_change_password'] as $flag) {
                if (array_key_exists($flag, $data)) {
                    $attributes[$flag] = (bool) $data[$flag];
                }
            }

            $user->update($attributes);
            $user->syncRoles($roles);

            return $user;
        });
    }

    /**
     * Soft-deletes a user (you cannot delete yourself or a user above your level).
     *
     * @throws ValidationException If the actor tries to delete themselves or may not manage the user.
     */
    public function delete(User $user, ?User $actor): void
    {
        if ($actor !== null && $actor->id === $user->id) {
            throw ValidationException::withMessages([
                'user' => 'Вы не можете удалить самого себя',
            ]);
        }

        $this->ensureCanManage($user, $actor);

        $user->delete();
    }

    /**
     * Moves selected users to trash, excluding the acting user and users the
     * actor may not manage.
     *
     * @param  array<int, int>  $ids
     * @return array{processed: int, skipped: int}
     */
    public function bulkDelete(array $ids, ?User $actor): array
    {
        return $this->applyDelete(User::query()->whereIn('id', $ids), $actor);
    }

    /**
     * Moves every user matching the current list filters to trash.
     *
     * @return array{processed: int, skipped: int}
     */
    public function bulkDeleteAll(?string $search, ?string $role, ?User $actor): array
    {
        return $this->applyDelete(
            User::query()->search($search)->filterByRole($role),
            $actor,
        );
    }

    /**
     * Restores a user from the trash.
     *
     * @param  int  $id  Identifier of the user in the trash
     *
     * @throws ValidationException If the email is taken by an active user or the actor may not manage the user.
     */
    public function restore(int $id, ?User $actor): void
    {
        $user = User::onlyTrashed()->findOrFail($id);

        $this->ensureCanManage($user, $actor);

        if ($this->emailTaken($user)) {
            throw ValidationException::withMessages([
                'user' => "Email {$user->email} уже занят активным пользователем",
            ]);
        }

        $user->restore();
    }

    /**
     * Permanently deletes a user from the trash (along with files/relations).
     *
     * @param  int  $id  Identifier of the user in the trash
     *
     * @throws ValidationException If the actor may not manage the user.
     */
    public function forceDelete(int $id, ?User $actor): void
    {
        $user = User::onlyTrashed()->findOrFail($id);

        $this->ensureCanManage($user, $actor);

        $user->forceDelete();
    }

    /**
     * Bulk restore from the trash. Users whose email is taken by an active
     * account, or who are above the actor's level, are skipped.
     *
     * @param  array<int, int>  $ids
     * @return array{processed: int, skipped: int}
     */
    public function bulkRestore(array $ids, ?User $actor): array
    {
        return $this->applyToTrashed(User::onlyTrashed()->whereIn('id', $ids), $actor, restore: true);
    }

    /**
     * Restores every trashed user matching the current list filter.
     *
     * @return array{processed: int, skipped: int}
     */
    public function bulkRestoreAll(?string $search, ?User $actor): array
    {
        return $this->applyToTrashed(User::onlyTrashed()->search($search), $actor, restore: true);
    }

    /**
     * Bulk permanent delete from the trash.
     *
     * @param  array<int, int>  $ids
     * @return array{processed: int, skipped: int}
     */
    public function bulkForceDelete(array $ids, ?User $actor): array
    {
        return $this->applyToTrashed(User::onlyTrashed()->whereIn('id', $ids), $actor, restore: false);
    }

    /**
     * Permanently deletes every trashed user matching the current list filter.
     *
     * @return array{processed: int, skipped: int}
     */
    public function bulkForceDeleteAll(?string $search, ?User $actor): array
    {
        return $this->applyToTrashed(User::onlyTrashed()->search($search), $actor, restore: false);
    }

    /**
     * Restores or force-deletes each user from a trash query.
     * Iterates over models (not a mass-update) so that restore/forceDelete
     * fire events and end up in the activity log (LogsActivity).
     *
     * @return array{processed: int, skipped: int}
     */
    private function applyToTrashed(Builder $query, ?User $actor, bool $restore): array
    {
        $processed = 0;
        $skipped = 0;

        $query->lazyById()->each(function (User $user) use ($actor, $restore, &$processed, &$skipped) {
            if (! RbacGuard::canManageUser($actor, $user) || ($restore && $this->emailTaken($user))) {
                $skipped++;

                return;
            }

            $restore ? $user->restore() : $user->forceDelete();
            $processed++;
        });

        return ['processed' => $processed, 'skipped' => $skipped];
    }

    /**
     * Soft-deletes a query in chunks while preserving model events.
     *
     * @return array{processed: int, skipped: int}
     */
    private function applyDelete(Builder $query, ?User $actor): array
    {
        if ($actor !== null) {
            $query->where('id', '!=', $actor->id);
        }

        $processed = 0;
        $skipped = 0;

        $query->lazyById()->each(function (User $user) use ($actor, &$processed, &$skipped) {
            if (! RbacGuard::canManageUser($actor, $user)) {
                $skipped++;

                return;
            }

            $user->delete();
            $processed++;
        });

        return ['processed' => $processed, 'skipped' => $skipped];
    }

    /** Whether an active user already uses the trashed user's email. */
    private function emailTaken(User $user): bool
    {
        return User::query()
            ->where('email', $user->email)
            ->whereKeyNot($user->getKey())
            ->exists();
    }

    /**
     * @throws ValidationException If the actor may not manage the user.
     */
    private function ensureCanManage(User $user, ?User $actor): void
    {
        if (! RbacGuard::canManageUser($actor, $user)) {
            throw ValidationException::withMessages([
                'user' => 'Недостаточно прав для действий с этим пользователем',
            ]);
        }
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
