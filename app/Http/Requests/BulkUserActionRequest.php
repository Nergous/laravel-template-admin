<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bulk operations on trashed users: restoration and
 * permanent deletion. Used in AdminUserController::bulkRestore()
 * and bulkForceDelete().
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
            'all' => ['sometimes', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
            'ids' => [
                Rule::requiredIf(fn () => ! $this->boolean('all')),
                'array',
                'min:1',
            ],
            'ids.*' => ['integer', 'exists:users,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Выберите хотя бы одного пользователя',
            'ids.min' => 'Выберите хотя бы одного пользователя',
            'ids.*.exists' => 'Один или несколько пользователей не найдены',
        ];
    }
}
