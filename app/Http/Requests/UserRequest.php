<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Support\RbacGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Form Request for creating and editing users.
 *
 * Roles are assigned via the roles[] array (spatie/laravel-permission role names).
 */
class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->isMethod('POST') ? 'users.create' : 'users.edit';

        return $this->user()?->can($permission) === true;
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;

        $rules = [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            // Uniqueness only among active users: an email from the trash can be reused
            // (matches the partial/composite UNIQUE from the soft-delete migration).
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($userId)->whereNull('deleted_at')],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name'), $this->roleAssignableByActor(...)],
        ];

        if ($this->isMethod('POST')) {
            $rules['password'] = ['required', Password::min(8)->mixedCase()->numbers()];
        }

        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            $rules['password'] = ['nullable', Password::min(8)->mixedCase()->numbers()];
        }

        return $rules;
    }

    protected function roleAssignableByActor(string $attribute, mixed $value, \Closure $fail): void
    {
        $actor = $this->user();

        if (! $actor) {
            $fail('Недостаточно прав для назначения роли.');

            return;
        }

        if (RbacGuard::isAdmin($actor)) {
            return;
        }

        $role = Role::where('name', $value)->with('permissions:id,name')->first();

        if (! $role) {
            return;
        }

        if (! RbacGuard::canManageRole($actor, $role)) {
            $fail('Системную роль может назначать только администратор.');

            return;
        }

        $missing = $role->permissions
            ->pluck('name')
            ->reject(fn (string $permission) => $actor->can($permission));

        if ($missing->isNotEmpty()) {
            $fail('Нельзя назначить роль с правами, которых у вас нет: '.$missing->implode(', '));
        }
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
