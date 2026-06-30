<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Support\RbacGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request для создания и редактирования ролей.
 *
 * Имя — латиница/цифры/. _ -, уникально. Права назначаются массивом
 * permissions[] (имена существующих разрешений).
 */
class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->isMethod('POST') ? 'roles.create' : 'roles.edit';

        if ($this->user()?->can($permission) !== true) {
            return false;
        }

        $role = $this->route('role');
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
     * Роли можно назначить только право, которым актор владеет сам (или если
     * он админ). Иначе держатель roles.create/roles.edit собирал бы роль с правами
     * выше своих и поднимал бы привилегии. При update это значит,
     * что не-админ может сохранять лишь роли, чей итоговый набор прав ⊆ его прав.
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
