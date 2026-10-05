<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Support\RbacGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request for creating and editing roles.
 *
 * The name is Latin letters/digits/. _ -, unique. Permissions are assigned via the
 * permissions[] array (names of existing permissions).
 */
class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $role = $this->route('role');
        $permission = $role instanceof Role ? 'roles.edit' : 'roles.create';

        if ($this->user()?->can($permission) !== true) {
            return false;
        }

        // Editing: the role must not be above the actor (system role, permissions the actor lacks).
        if ($role instanceof Role && ! RbacGuard::canManageRole($this->user(), $role)) {
            return false;
        }

        return true;
    }

    public function rules(): array
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
            'permissions.*' => ['string', Rule::exists('permissions', 'name'), $this->permissionAssignableByActor(...)],
        ];
    }

    /**
     * A role can only be assigned a permission that the actor holds themselves (or if
     * they are an admin). Otherwise the holder of roles.create/roles.edit could assemble
     * a role with permissions above their own and escalate privileges. On update this means
     * that a non-admin can only save roles whose resulting permission set ⊆ their permissions.
     */
    protected function permissionAssignableByActor(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (! RbacGuard::canGrantPermission($this->user(), $value)) {
            $fail('Нельзя назначить роли право, которого у вас нет: '.$value);
        }
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
