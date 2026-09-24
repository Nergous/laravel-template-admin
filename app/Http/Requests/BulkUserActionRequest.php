<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bulk operations on active and trashed users.
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
            'role' => [
                'nullable', 'string',
                Rule::when($this->input('role') !== User::WITHOUT_ROLES, ['exists:roles,name']),
            ],
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
