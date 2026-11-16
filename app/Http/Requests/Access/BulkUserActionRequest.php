<?php

namespace App\Http\Requests\Access;

/**
 * Bulk deletion of active users and bulk restore or permanent deletion in the
 * trash (all under the users.delete permission).
 */
class BulkUserActionRequest extends UserSelectionRequest
{
    protected function permissions(): array
    {
        return ['users.delete'];
    }

    public function rules(): array
    {
        return [
            ...parent::rules(),
            // bulk-destroy: delete permanently instead of moving to the trash.
            'force' => ['sometimes', 'boolean'],
        ];
    }
}
