<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request для массового удаления медиа.
 *
 * Валидирует массив идентификаторов перед удалением.
 * Используется в AdminMediaController::bulkDestroy().
 */
class BulkDestroyMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('media.delete') === true;
    }

    /**
     * Правила валидации:
     * - ids        — обязательный массив идентификаторов
     * - ids.*      — каждый элемент должен быть целым числом
     *               и существовать в таблице media
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
