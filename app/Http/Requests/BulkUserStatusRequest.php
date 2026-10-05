<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\SelectsUsers;
use Illuminate\Foundation\Http\FormRequest;

/** Validates a bulk block or unblock action with the current list filters. */
class BulkUserStatusRequest extends FormRequest
{
    use SelectsUsers;

    public function authorize(): bool
    {
        return $this->user()?->can('users.edit') === true;
    }

    public function rules(): array
    {
        return [
            'active' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:255'],
            ...$this->userSelectionRules(),
        ];
    }
}
