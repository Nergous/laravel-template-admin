<?php

namespace App\Http\Requests\Access;

use App\Services\Access\RbacGuard;
use Illuminate\Validation\Rules\Password;

/**
 * Editing a user: the password changes only when given, and status and role
 * fields that were not sent stay as they were. Whether the actor may edit this
 * account at all is decided by UserPolicy::update on the route.
 */
class UpdateUserRequest extends UserRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.edit') === true;
    }

    public function rules(RbacGuard $guard): array
    {
        return [
            ...$this->commonRules($guard),
            'password' => ['nullable', Password::defaults()],
        ];
    }

    /**
     * Attributes for UserService::update().
     *
     * @return array{name: string, email: string, password?: string|null, is_active?: bool, blocked_reason?: string|null}
     */
    public function userData(): array
    {
        $data = $this->safe()->only(['name', 'email', 'password']);

        if ($this->has('is_active')) {
            $data['is_active'] = (bool) $this->validated('is_active');
        }

        if ($this->has('blocked_reason')) {
            $data['blocked_reason'] = $this->validated('blocked_reason');
        }

        return $data;
    }

    /**
     * Role names to sync, or null when roles[] was not sent: a client without
     * this key keeps the user's roles.
     *
     * @return list<string>|null
     */
    public function roleNames(): ?array
    {
        return $this->exists('roles') ? array_values($this->validated('roles') ?? []) : null;
    }
}
