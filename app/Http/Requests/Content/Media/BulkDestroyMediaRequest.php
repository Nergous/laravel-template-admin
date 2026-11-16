<?php

namespace App\Http\Requests\Content\Media;

/** Bulk file deletion: checked ids, or all=1 with the list filters. */
class BulkDestroyMediaRequest extends MediaSelectionRequest
{
    protected function permissions(): array
    {
        return ['media.delete'];
    }
}
