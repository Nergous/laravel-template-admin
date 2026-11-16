<?php

namespace App\Http\Requests\Access;

use App\Services\Access\RbacGuard;
use Illuminate\Validation\Rules\Password;

/** Creating a user: the password is required, the account is active unless stated otherwise. */
class StoreUserRequest extends UserRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.create') === true;
    }

    public function rules(RbacGuard $guard): array
    {
        return [
            ...$this->commonRules($guard),
            'password' => ['required', Password::defaults()],
        ];
    }

    /**
     * Attributes for UserService::create().
     *
     * @return array{name: string, email: string, password: string, is_active: bool, blocked_reason: string|null}
     */
    public function userData(): array
    {
        return [
            ...$this->safe()->only(['name', 'email', 'password']),
            'is_active' => (bool) $this->validated('is_active', true),
            'blocked_reason' => $this->validated('blocked_reason'),
        ];
    }

    /** @return list<string> */
    public function roleNames(): array
    {
        return array_values($this->validated('roles') ?? []);
    }
}
