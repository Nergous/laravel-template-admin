<?php

namespace App\Http\Requests\Access;

use App\Http\Requests\Concerns\PaginatesList;
use App\Http\Requests\Concerns\SortsList;
use Illuminate\Foundation\Http\FormRequest;

/** User trash filters: search text, sorting and page size. */
class UserTrashFilterRequest extends FormRequest
{
    use PaginatesList;
    use SortsList;

    public function authorize(): bool
    {
        return $this->user()?->can('users.delete') === true;
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
        return ['id', 'name', 'email', 'created_at', 'deleted_at'];
    }
}
