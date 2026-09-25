<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Renaming (PATCH: folder + name) or dissolving (DELETE: folder) a media folder.
 */
class MediaFolderRequest extends FormRequest
{
    private const NAME_RULE = 'not_regex:/[\/\\\\\x00-\x1F\x7F]/';

    public function authorize(): bool
    {
        return $this->user()?->can('media.edit') === true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['folder', 'name'] as $field) {
            if ($this->has($field)) {
                $this->merge([$field => trim((string) $this->input($field))]);
            }
        }
    }

    public function rules(): array
    {
        $rules = [
            'folder' => ['required', 'string', 'max:100', Rule::exists('media', 'folder')],
        ];

        if ($this->isMethod('PATCH')) {
            $rules['name'] = ['required', 'string', 'max:100', self::NAME_RULE];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'folder.exists' => 'Такой папки нет',
            'name.required' => 'Введите название папки',
            'name.max' => 'Название папки не должно превышать :max символов',
            'name.not_regex' => 'Название папки не должно содержать слеши и управляющие символы',
        ];
    }
}
