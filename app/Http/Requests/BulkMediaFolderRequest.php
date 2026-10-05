<?php

namespace App\Http\Requests;

use App\Http\Controllers\Admin\AdminMediaController;
use App\Support\MediaFolderPath;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Moving files into the target folder by path ("Баннеры/2026"); missing
 * folders are created, an empty path takes the files out of any folder.
 *
 * Picks the ids, or all=1 with the list filters flat under the names of the
 * index query (search, type, folder, usage). folder is therefore the list
 * filter and the destination goes in target. A request without target and
 * without all=1 is the older form, where folder named the destination.
 */
class BulkMediaFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('media.edit') === true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('target') && ! $this->boolean('all') && $this->has('folder')) {
            $this->merge(['target' => $this->input('folder')]);
        }

        if ($this->has('target')) {
            $this->merge(['target' => MediaFolderPath::normalize($this->input('target'))]);
        }
    }

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
            'target' => ['present', 'nullable', 'string', 'max:'.MediaFolderPath::MAX, ...MediaFolderPath::PATH_RULES],
        ];
    }

    public function messages(): array
    {
        return [
            'ids.required' => 'Выберите хотя бы один файл',
            'ids.*.exists' => 'Один или несколько файлов не найдены',
            'target.present' => 'Укажите папку, в которую переместить файлы',
            'target.max' => 'Путь к папке не должен превышать :max символов',
            'target.not_regex' => 'Путь к папке не должен содержать «\\», управляющие символы и части «.» или «..»',
        ];
    }
}
