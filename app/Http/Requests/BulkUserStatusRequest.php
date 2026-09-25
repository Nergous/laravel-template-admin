<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validates a bulk block or unblock action with the current list filters. */
class BulkUserStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.edit') === true;
    }

    public function rules(): array
    {
        return [
            'active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
            'all' => ['sometimes', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
            'role' => [
                'nullable', 'string',
                Rule::when($this->input('role') !== User::WITHOUT_ROLES, ['exists:roles,name']),
            ],
            'status' => ['nullable', Rule::in(['active', 'blocked'])],
            'must_change_password' => ['nullable', 'boolean'],
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
