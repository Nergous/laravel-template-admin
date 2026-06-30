<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request for creating and renaming permissions.
 *
 * The name is restricted to Latin letters, digits and the characters . _ - and must be unique.
 */
class PermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Access to the section is already gated by the permission:permissions.view
        // middleware on the route (routes/web.php). Here — a fine-grained gate of the
        // action by HTTP method: POST (store) requires permissions.create, PUT/PATCH
        // (update) — permissions.edit. This eliminates the crude create || edit, under
        // which an edit-only owner could create, and a create-only owner could edit.
        $permission = $this->isMethod('POST') ? 'permissions.create' : 'permissions.edit';

        return $this->user()?->can($permission) === true;
    }

    public function rules(): array
    {
        $permissionId = $this->route('permission')?->id;

        return [
            'name' => [
                'required', 'string', 'max:125',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('permissions', 'name')->ignore($permissionId),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Имя разрешения обязательно',
            'name.unique' => 'Разрешение с таким именем уже существует',
            'name.regex' => 'Имя разрешения может содержать только латинские буквы, цифры и символы . _ -',
        ];
    }
}
