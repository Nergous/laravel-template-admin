<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Access\RbacGuard;

/**
 * Record-level rules for accounts: whether the actor may touch this account at
 * all. Whether they may perform the action in general (users.edit,
 * users.delete, …) is decided by the "permission:" route middleware and the
 * FormRequest.
 *
 * The superadmin passes every rule before this policy (Gate::before in
 * AppServiceProvider). Everyone may change their own account. Otherwise the
 * target must have no system role and no permissions the actor lacks —
 * otherwise a users.edit holder could take over an administrator by changing
 * their email or password.
 *
 * Rules about one's own account (cannot delete or block oneself, cannot remove
 * the superadmin role from oneself) live in UserService: they must apply to the
 * superadmin too, and the Gate lets them through before the policy.
 */
class UserPolicy
{
    public function __construct(private readonly RbacGuard $rbac) {}

    public function update(User $actor, User $target): bool
    {
        return $this->manages($actor, $target);
    }

    public function delete(User $actor, User $target): bool
    {
        return $this->manages($actor, $target);
    }

    public function restore(User $actor, User $target): bool
    {
        return $this->manages($actor, $target);
    }

    public function forceDelete(User $actor, User $target): bool
    {
        return $this->manages($actor, $target);
    }

    private function manages(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return true;
        }

        $target->loadMissing('roles');

        return ! $target->roles->contains('is_system', true)
            && $this->rbac->coversUser($actor, $target);
    }
}
