<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request для создания и переименования разрешений.
 *
 * Имя ограничено латиницей, цифрами и символами . _ - и должно быть уникальным.
 */
class PermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ к разделу уже закрыт middleware permission:permissions.view на
        // маршруте (routes/web.php). Здесь — тонкий гейт действия по HTTP-методу:
        // POST (store) требует permissions.create, PUT/PATCH (update) —
        // permissions.edit. Это устраняет грубое create || edit, при котором
        // владелец только edit мог создавать, а владелец только create — редактировать.
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
