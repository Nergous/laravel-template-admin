<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\SelectsUsers;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Bulk deletion of active users and bulk restore/permanent deletion in the
 * trash (all under users.delete).
 */
class BulkUserActionRequest extends FormRequest
{
    use SelectsUsers;

    public function authorize(): bool
    {
        return $this->user()?->can('users.delete') === true;
    }

    public function rules(): array
    {
        return $this->userSelectionRules();
    }
}
