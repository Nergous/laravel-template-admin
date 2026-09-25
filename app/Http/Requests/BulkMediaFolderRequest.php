<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkMediaFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('media.edit') === true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('folder')) {
            $folder = trim((string) $this->input('folder'));
            $this->merge(['folder' => $folder === '' ? null : $folder]);
        }
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct', 'exists:media,id'],
            'folder' => ['present', 'nullable', 'string', 'max:100', 'not_regex:/[\/\\\\\x00-\x1F\x7F]/'],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Выберите хотя бы один файл',
            'ids.*.exists' => 'Один или несколько файлов не найдены',
            'folder.max' => 'Название папки не должно превышать :max символов',
            'folder.not_regex' => 'Название папки не должно содержать слеши и управляющие символы',
        ];
    }
}
