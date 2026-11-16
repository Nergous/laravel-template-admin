<?php

namespace App\Http\Requests\Access;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Several matrix cells at once: a whole row (one permission for several roles)
 * or a group column (several permissions for one role).
 */
class BulkTogglePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('permissions.edit') === true;
    }

    public function rules(): array
    {
        return [
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', 'exists:permissions,name'],
            'granted' => ['required', 'boolean'],
        ];
    }
}
