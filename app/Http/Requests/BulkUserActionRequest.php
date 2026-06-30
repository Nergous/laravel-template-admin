<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Массовые операции над пользователями в корзине: восстановление и
 * окончательное удаление. Используется в AdminUserController::bulkRestore()
 * и bulkForceDelete().
 */
class BulkUserActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.delete') === true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Выберите хотя бы одного пользователя',
            'ids.*.exists' => 'Один или несколько пользователей не найдены',
        ];
    }
}
