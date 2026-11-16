<?php

namespace App\Http\Requests\Content\Media;

use App\Enums\MediaType;
use App\Enums\MediaUsageFilter;
use App\Http\Requests\Concerns\PaginatesList;
use App\Http\Requests\Concerns\SortsList;
use App\Support\Media\MediaFilters;
use App\Support\Media\MediaFolderPath;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Media list filters: search, type, the open folder ("Banners/2026", empty —
 * the root level) and usage (used, unused, images without alt).
 */
class MediaFilterRequest extends FormRequest
{
    use PaginatesList;
    use SortsList;

    public function authorize(): bool
    {
        return $this->user()?->can('media.view') === true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::enum(MediaType::class)],
            'folder' => ['nullable', 'string', 'max:'.MediaFolderPath::MAX],
            'usage' => ['nullable', Rule::enum(MediaUsageFilter::class)],
        ];
    }

    public function filters(): MediaFilters
    {
        return MediaFilters::fromInput($this->validated());
    }

    /** The grid shows several files per row, so the page is larger. */
    protected function defaultPerPage(): int
    {
        return 25;
    }

    protected function sortable(): array
    {
        return ['id', 'original_name', 'created_at', 'size', 'mime_type'];
    }

    /** Newest uploads first. */
    protected function defaultSort(): string
    {
        return 'created_at';
    }
}
