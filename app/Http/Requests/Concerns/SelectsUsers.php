<?php

namespace App\Http\Requests\Concerns;

use App\Models\Role;
use App\Models\User;
use App\Rules\FilterList;
use Illuminate\Validation\Rule;

/**
 * The selection of a bulk user action: either ids[], or all=1 with the user
 * list filters (the same query parameter names as the list page:
 * search, role, status, must_change_password). The role filter takes several
 * role names (comma-separated or an array), any of them matches.
 */
trait SelectsUsers
{
    /** @return array<string, mixed> */
    protected function userSelectionRules(): array
    {
        return [
            'all' => ['sometimes', 'boolean'],
            'search' => ['nullable', 'string', 'max:255'],
            'role' => [
                'nullable',
                FilterList::of([User::WITHOUT_ROLES, ...Role::query()->pluck('name')->all()]),
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

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ids.required' => 'Выберите хотя бы одного пользователя',
            'ids.min' => 'Выберите хотя бы одного пользователя',
            'ids.*.exists' => 'Один или несколько пользователей не найдены',
        ];
    }
}
