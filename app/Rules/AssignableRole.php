<?php

namespace App\Rules;

use App\Models\Role;
use App\Models\User;
use App\Services\Access\RbacGuard;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Collection;

/**
 * A role the actor may assign to a user. The administrator assigns any. Others
 * cannot assign system roles or roles with permissions they lack. A role the
 * edited user already has does not count as granting: an operator with
 * users.edit can save their own profile without losing the system role.
 */
final class AssignableRole implements ValidationRule
{
    /** @var Collection<string, Role>|null Roles with permissions by name, loaded once for all roles.* items */
    private ?Collection $roles = null;

    public function __construct(
        private readonly RbacGuard $guard,
        private readonly ?User $actor,
        private readonly ?User $target = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->actor === null) {
            $fail('Недостаточно прав для назначения роли.');

            return;
        }

        if (! is_string($value) || $this->guard->isSuperadmin($this->actor) || $this->target?->hasRole($value)) {
            return;
        }

        $role = $this->roles()->get($value);

        if ($role === null) {
            return;
        }

        if ($role->is_system) {
            $fail('Системную роль может назначать только администратор.');

            return;
        }

        $missing = $this->guard->missingPermissions($this->actor, $role);

        if ($missing->isNotEmpty()) {
            $fail('Нельзя назначить роль с правами, которых у вас нет: '.$missing->implode(', '));
        }
    }

    /** @return Collection<string, Role> */
    private function roles(): Collection
    {
        return $this->roles ??= Role::query()->with('permissions:id,name')->get()->keyBy('name');
    }
}
