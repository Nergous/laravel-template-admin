<?php

namespace App\Http\Requests\Access;

use App\Http\Requests\Concerns\ChoosesExport;

/** Export of the filtered user list: the list filters plus format and columns. */
class ExportUsersRequest extends UserFilterRequest
{
    use ChoosesExport;

    public function authorize(): bool
    {
        return parent::authorize() && $this->user()->can('users.export');
    }

    public function rules(): array
    {
        return [...parent::rules(), ...$this->exportRules()];
    }
}
