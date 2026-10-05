<?php

namespace App\Http\Requests;

use App\Http\Controllers\Admin\AdminMediaController;
use App\Support\MediaFolderPath;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Form Request for bulk media deletion.
 *
 * Takes the picked ids, or all=1 with the list filters flat, under the
 * names of the index query (search, type, folder, usage) — the "every
 * matching file" contract shared by the admin lists.
 * Used in AdminMediaController::bulkDestroy().
 */
class BulkDestroyMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('media.delete') === true;
    }

    /**
     * Validation rules:
     * - ids        — array of identifiers (required unless all=1)
     * - ids.*      — each element must be an integer
     *               and exist in the media table
     * - all + search/type/folder/usage — every file the list shows for these filters
     */
    public function rules(): array
    {
        return [
            'all' => ['sometimes', 'boolean'],
            'ids' => [Rule::requiredIf(fn () => ! $this->boolean('all')), 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:media,id'],
            // With all=1: the list filters that pick the files.
            'search' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', Rule::in(AdminMediaController::TYPES)],
            'folder' => ['nullable', 'string', 'max:'.MediaFolderPath::MAX],
            'usage' => ['nullable', Rule::in(AdminMediaController::USAGES)],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Выберите хотя бы один файл для удаления',
            'ids.*.exists' => 'Один или несколько файлов не найдены',
        ];
    }
}
