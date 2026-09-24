<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request for renaming a media file (the display name, original_name).
 *
 * The physical file on disk is not touched, so references to its path remain
 * valid. Used in AdminMediaController::update().
 */
class RenameMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('media.edit') === true;
    }

    /**
     * Validation rules:
     * - original_name — required display name; path separators and control
     *   characters are rejected so the name stays a plain label.
     */
    public function rules(): array
    {
        return [
            'original_name' => ['required', 'string', 'max:255', 'not_regex:/[\/\\\\\x00-\x1F]/'],
        ];
    }

    public function messages(): array
    {
        return [
            'original_name.required' => 'Введите имя файла',
            'original_name.max' => 'Имя файла не должно превышать :max символов',
            'original_name.not_regex' => 'Имя файла не должно содержать символы / и \\',
        ];
    }
}
