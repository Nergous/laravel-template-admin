<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request for updating editable media metadata.
 */
class RenameMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('media.edit') === true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['original_name', 'alt', 'folder'] as $field) {
            if (! $this->has($field)) {
                continue;
            }

            $value = trim((string) $this->input($field));
            $this->merge([$field => $value === '' && $field !== 'original_name' ? null : $value]);
        }
    }

    public function rules(): array
    {
        return [
            'original_name' => ['sometimes', 'required', 'string', 'max:255', 'not_regex:/[\/\\\\\x00-\x1F\x7F]/'],
            'alt' => ['sometimes', 'nullable', 'string', 'max:255'],
            'folder' => ['sometimes', 'nullable', 'string', 'max:100', 'not_regex:/[\/\\\\\x00-\x1F\x7F]/'],
            // Focal point as fractions of the width/height, always sent as a pair;
            // null resets it to the center. No "sometimes": it would skip the
            // required_with check on the missing half.
            'focal_x' => ['nullable', 'numeric', 'min:0', 'max:1', 'required_with:focal_y'],
            'focal_y' => ['nullable', 'numeric', 'min:0', 'max:1', 'required_with:focal_x'],
        ];
    }

    public function messages(): array
    {
        return [
            'original_name.required' => 'Введите имя файла',
            'original_name.max' => 'Имя файла не должно превышать :max символов',
            'original_name.not_regex' => 'Имя файла не должно содержать символы / и \\',
            'alt.max' => 'Alt-текст не должен превышать :max символов',
            'folder.max' => 'Название папки не должно превышать :max символов',
            'folder.not_regex' => 'Название папки не должно содержать слеши и управляющие символы',
        ];
    }
}
