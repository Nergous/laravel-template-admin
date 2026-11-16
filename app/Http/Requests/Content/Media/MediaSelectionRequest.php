<?php

namespace App\Http\Requests\Content\Media;

use App\Enums\MediaType;
use App\Enums\MediaUsageFilter;
use App\Http\Requests\SelectionRequest;
use App\Support\Media\MediaFolderPath;
use Illuminate\Validation\Rule;

/**
 * Files selected for a bulk action (see SelectionRequest). The filters with
 * all=1 are those of the media list: search, type, folder, usage.
 */
abstract class MediaSelectionRequest extends SelectionRequest
{
    protected function filterRules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::enum(MediaType::class)],
            'folder' => ['nullable', 'string', 'max:'.MediaFolderPath::MAX],
            'usage' => ['nullable', Rule::enum(MediaUsageFilter::class)],
        ];
    }

    protected function emptySelectionMessage(): string
    {
        return 'Выберите хотя бы один файл';
    }
}
