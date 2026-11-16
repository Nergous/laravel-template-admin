<?php

namespace App\Http\Requests\Access;

/** Bulk block or unblock of the selected users. */
class BulkUserStatusRequest extends UserSelectionRequest
{
    protected function permissions(): array
    {
        return ['users.edit'];
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
