<?php

namespace App\Http\Requests\Access;

use App\Enums\UserStatus;
use App\Http\Requests\Concerns\PaginatesList;
use App\Http\Requests\Concerns\SortsList;
use App\Rules\FilterList;
use App\Support\FilterValues;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters and sorting of the user list and its export: search, role (several
 * names comma-separated or as an array, any of them matches) and status. The
 * role filter is lenient: a link with a deleted role simply finds nobody.
 */
class UserFilterRequest extends FormRequest
{
    use PaginatesList;
    use SortsList;

    public function authorize(): bool
    {
        return $this->user()?->can('users.view') === true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'role' => ['nullable', FilterList::any()],
            'status' => ['nullable', Rule::enum(UserStatus::class)],
        ];
    }

    /** @return array{search: string|null, role: list<string>, status: string|null} */
    public function filters(): array
    {
        return [
            'search' => $this->validated('search'),
            'role' => FilterValues::strings($this->validated('role')),
            'status' => $this->validated('status'),
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'name', 'email', 'last_login_at', 'created_at'];
    }
}
