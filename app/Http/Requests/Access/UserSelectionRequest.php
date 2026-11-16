<?php

namespace App\Http\Requests\Access;

use App\Enums\UserStatus;
use App\Http\Requests\SelectionRequest;
use App\Models\Role;
use App\Models\User;
use App\Rules\FilterList;
use Illuminate\Validation\Rule;

/**
 * Users selected for a bulk action (see SelectionRequest). Filters with all=1
 * are the same as the user list's: search, role, status. The role takes several
 * names (comma-separated or as an array) and any of them matches, so each must
 * exist: otherwise a typo in the second name would silently select everyone
 * with the first role.
 */
abstract class UserSelectionRequest extends SelectionRequest
{
    protected function filterRules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', FilterList::of([User::WITHOUT_ROLES, ...Role::query()->pluck('name')->all()])],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
        ];
    }

    protected function emptySelectionMessage(): string
    {
        return 'Выберите хотя бы одного пользователя';
    }
}
