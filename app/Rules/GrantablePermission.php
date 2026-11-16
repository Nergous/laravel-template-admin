<?php

namespace App\Rules;

use App\Models\User;
use App\Services\Access\RbacGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A permission the actor may grant to a role: one they hold themselves (any
 * for the superadmin). Otherwise a roles.create/roles.edit holder could build a
 * role with permissions above their own and escalate their privileges.
 */
final class GrantablePermission implements ValidationRule
{
    public function __construct(
        private readonly RbacGuard $guard,
        private readonly ?User $actor,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ($this->actor === null || ! $this->guard->canGrantPermission($this->actor, $value))) {
            $fail('Нельзя назначить роли право, которого у вас нет: '.$value);
        }
    }
}
