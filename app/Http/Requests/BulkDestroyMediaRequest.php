<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request for bulk media deletion.
 *
 * Validates the array of identifiers before deletion.
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
     * - ids        — required array of identifiers
     * - ids.*      — each element must be an integer
     *               and exist in the media table
     */
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:media,id'],
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
