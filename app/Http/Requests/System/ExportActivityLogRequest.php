<?php

namespace App\Http\Requests\System;

use App\Http\Requests\Concerns\ChoosesExport;

/** Export of the filtered activity log: the list filters plus format and columns. */
class ExportActivityLogRequest extends ActivityLogFilterRequest
{
    use ChoosesExport;

    public function rules(): array
    {
        return [...parent::rules(), ...$this->exportRules()];
    }
}
