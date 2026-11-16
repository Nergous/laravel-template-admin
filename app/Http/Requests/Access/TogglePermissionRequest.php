<?php

namespace App\Http\Requests\Access;

use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;

/** One access matrix cell: grant or revoke a permission for a role. */
class TogglePermissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('permissions.edit') === true;
    }

    public function rules(): array
    {
        return [
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'permission' => ['required', 'string', 'exists:permissions,name'],
            'granted' => ['required', 'boolean'],
        ];
    }

    /** The role from role_id: validation has already checked it exists. */
    public function role(): Role
    {
        return Role::findOrFail($this->integer('role_id'));
    }
}
