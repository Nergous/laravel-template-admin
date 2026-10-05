<?php

namespace App\Http\Requests;

use App\Models\MediaFolder;
use App\Support\MediaFolderPath;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating (POST: parent + name), renaming (PATCH: folder + name) or deleting
 * (DELETE: folder) a media folder. folder and parent are full paths; name is
 * a single folder name.
 */
class MediaFolderRequest extends FormRequest
{
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

        if ($this->has('parent')) {
            $this->merge(['parent' => MediaFolderPath::normalize($this->input('parent'))]);
        }
    }

    public function rules(): array
    {
        $name = ['required', 'string', 'max:'.MediaFolderPath::NAME_MAX, MediaFolderPath::NAME_RULE];
        $existing = ['string', 'max:'.MediaFolderPath::MAX, Rule::exists('media_folders', 'name')];

        if ($this->isMethod('POST')) {
            return ['parent' => ['nullable', ...$existing], 'name' => $name];
        }

        $rules = ['folder' => ['required', ...$existing]];

        if ($this->isMethod('PATCH')) {
            $rules['name'] = $name;
        }

        return $rules;
    }

    /** The resulting path must fit, and a new folder must not exist yet. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || ! $this->has('name')) {
                return;
            }

            $parent = $this->isMethod('POST')
                ? $this->input('parent')
                : MediaFolderPath::parent((string) $this->input('folder'));
            $path = MediaFolderPath::join($parent, (string) $this->input('name'));

            if (mb_strlen($path) > MediaFolderPath::MAX) {
                $validator->errors()->add('name', 'Слишком длинный путь к папке — сократите названия');
            } elseif ($this->isMethod('POST') && MediaFolder::where('name', $path)->exists()) {
                $validator->errors()->add('name', 'Папка с таким названием уже есть');
            }
        });
    }

    public function messages(): array
    {
        return [
            'folder.exists' => 'Такой папки нет',
            'parent.exists' => 'Родительская папка не найдена — обновите страницу',
            'name.required' => 'Введите название папки',
            'name.max' => 'Название папки не должно превышать :max символов',
            'name.not_regex' => 'Название папки не должно содержать слеши и управляющие символы',
        ];
    }
}
