<?php

namespace App\Http\Requests\Content\Media;

use App\Rules\SafeFolderName;
use App\Support\Media\MediaFolderPath;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared part of the media folder requests (create, rename, delete):
 * access, input cleanup, rules and messages. folder and parent are full paths
 * ("Banners/2026"); name is the name of one folder.
 */
abstract class MediaFolderRequest extends FormRequest
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

    /** @return list<mixed> */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:'.MediaFolderPath::NAME_MAX, new SafeFolderName];
    }

    /** @return list<mixed> */
    protected function existingFolderRules(): array
    {
        return ['string', 'max:'.MediaFolderPath::MAX, Rule::exists('media_folders', 'name')];
    }

    /**
     * The path name gives inside $parent, or null (with an error on name) when
     * it does not fit.
     */
    protected function targetPath(Validator $validator, ?string $parent): ?string
    {
        $path = MediaFolderPath::join($parent, (string) $this->input('name'));

        if (mb_strlen($path) > MediaFolderPath::MAX) {
            $validator->errors()->add('name', 'Слишком длинный путь к папке — сократите названия');

            return null;
        }

        return $path;
    }

    public function messages(): array
    {
        return [
            'folder.exists' => 'Такой папки нет',
            'parent.exists' => 'Родительская папка не найдена — обновите страницу',
            'name.required' => 'Введите название папки',
            'name.max' => 'Название папки не должно превышать :max символов',
        ];
    }
}
