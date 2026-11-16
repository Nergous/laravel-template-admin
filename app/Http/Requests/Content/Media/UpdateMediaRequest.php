<?php

namespace App\Http\Requests\Content\Media;

use App\Rules\SafeFolderPath;
use App\Support\Media\MediaFolderPath;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Editable media metadata: display name, alt text, folder and focal point
 * (MediaController::update()). The stored file does not change.
 */
class UpdateMediaRequest extends FormRequest
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
            if ($field === 'folder') {
                $value = (string) MediaFolderPath::normalize($value);
            }
            $this->merge([$field => $value === '' && $field !== 'original_name' ? null : $value]);
        }
    }

    public function rules(): array
    {
        return [
            'original_name' => ['sometimes', 'required', 'string', 'max:255', 'not_regex:/[\/\\\\\x00-\x1F\x7F]/'],
            'alt' => ['sometimes', 'nullable', 'string', 'max:255'],
            'folder' => ['sometimes', 'nullable', 'string', 'max:'.MediaFolderPath::MAX, new SafeFolderPath],
            // Focal point as fractions of width/height, always sent as a pair; null
            // resets it to the center. No "sometimes": otherwise the required_with
            // check for the missing half would be skipped.
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
            'folder.max' => 'Путь к папке не должен превышать :max символов',
        ];
    }
}
