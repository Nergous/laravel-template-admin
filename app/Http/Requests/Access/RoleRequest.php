<?php

namespace App\Http\Requests\Access;

use App\Rules\GrantablePermission;
use App\Services\Access\RbacGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form request for creating and editing roles.
 *
 * The name is Latin letters, digits and . _ -, unique. Permissions are assigned
 * via permissions[] (names of existing permissions), and the actor must hold
 * each of them (GrantablePermission). On update this means a non-administrator
 * can save only a role whose resulting permission set ⊆ their own permissions.
 *
 * Access is checked by the routes: roles.create / roles.edit and, when editing,
 * RolePolicy::update ("can:update,role").
 */
class RoleRequest extends FormRequest
{
    /** Re-checks the route permission (defense in depth); RolePolicy runs on the route. */
    public function authorize(): bool
    {
        return $this->user()?->can($this->route('role') ? 'roles.edit' : 'roles.create') === true;
    }

    public function rules(RbacGuard $guard): array
    {
        $roleId = $this->route('role')?->id;

        return [
            'name' => [
                'required', 'string', 'max:125',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('roles', 'name')->ignore($roleId),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::exists('permissions', 'name'), new GrantablePermission($guard, $this->user())],
        ];
    }

    /** @return array{name: string, description?: string|null} */
    public function fields(): array
    {
        return $this->safe()->only(['name', 'description']);
    }

    /**
     * Permission names to sync, or null when the form did not send the key:
     * a client without permissions[] keeps the role's current permission set.
     *
     * @return list<string>|null
     */
    public function permissionsToSync(): ?array
    {
        return $this->exists('permissions') ? array_values($this->validated('permissions') ?? []) : null;
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Имя роли обязательно',
            'name.unique' => 'Роль с таким именем уже существует',
            'name.regex' => 'Имя роли может содержать только латинские буквы, цифры и символы . _ -',
        ];
    }
}
