<?php

namespace App\Http\Requests\Access;

use App\Models\User;
use App\Rules\AssignableRole;
use App\Services\Access\RbacGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shared rules of the user create and edit forms (StoreUserRequest,
 * UpdateUserRequest). Roles are assigned via roles[] (spatie/laravel-permission
 * role names); AssignableRole decides which of them the actor may assign.
 */
abstract class UserRequest extends FormRequest
{
    /** @return array<string, mixed> */
    protected function commonRules(RbacGuard $guard): array
    {
        $target = $this->route('user');
        $target = $target instanceof User ? $target : null;

        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            // Unique among active users only: an email from the trash can be taken
            // again (matches the partial/composite UNIQUE of the soft-delete migration).
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($target?->id)->whereNull('deleted_at')],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name'), new AssignableRole($guard, $this->user(), $target)],
            'is_active' => ['sometimes', 'boolean'],
            'blocked_reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Имя обязательно для заполнения',
            'name.min' => 'Имя должно содержать минимум :min символа',
            'email.required' => 'Email обязателен для заполнения',
            'email.unique' => 'Пользователь с таким email уже существует',
            'password.required' => 'Пароль обязателен для заполнения',
            'password.min' => 'Пароль должен содержать минимум :min символов',
        ];
    }
}
