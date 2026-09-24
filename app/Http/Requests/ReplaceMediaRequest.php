<?php

namespace App\Http\Requests;

/**
 * Form Request for replacing the file of an existing media record.
 *
 * Accepts one file with the same format, size, and pixel limits as a regular
 * upload (see MediaRequest). Gated by media.edit: the record stays, only its
 * file changes.
 */
class ReplaceMediaRequest extends MediaRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('media.edit') === true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:'.implode(',', self::ALLOWED_EXTENSIONS),
                'max:'.self::MAX_SIZE_KB,
                $this->imageWithinPixelLimit(...),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Выберите файл',
            'file.file' => 'Не удалось загрузить файл',
            'file.mimes' => 'Недопустимый формат файла',
            'file.max' => 'Размер файла не должен превышать 50 МБ',
        ];
    }
}
