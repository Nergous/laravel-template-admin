<?php

namespace App\Http\Requests\Access;

use App\Http\Requests\Concerns\PaginatesList;
use App\Http\Requests\Concerns\SortsList;
use Illuminate\Foundation\Http\FormRequest;

/** Role list filters: search text, sorting and page size. */
class RoleFilterRequest extends FormRequest
{
    use PaginatesList;
    use SortsList;

    public function authorize(): bool
    {
        return $this->user()?->can('roles.view') === true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function search(): ?string
    {
        return $this->validated('search');
    }

    protected function sortable(): array
    {
        return ['id', 'name', 'is_system', 'users_count', 'permissions_count', 'created_at'];
    }

    protected function defaultSort(): string
    {
        return 'name';
    }

    protected function defaultDirection(): string
    {
        return 'asc';
    }
}
