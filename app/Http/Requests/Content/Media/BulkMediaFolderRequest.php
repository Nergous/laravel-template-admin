<?php

namespace App\Http\Requests\Content\Media;

use App\Rules\SafeFolderPath;
use App\Support\Media\MediaFolderPath;

/**
 * Moves files to a target folder by path ("Banners/2026"); missing folders
 * are created, an empty path takes the files out of any folder.
 *
 * Selects ids or all=1 with the list filters (see MediaSelectionRequest),
 * so folder is the list filter and the destination comes in target.
 * A request without target and without all=1 is the old form where folder was the destination.
 */
class BulkMediaFolderRequest extends MediaSelectionRequest
{
    protected function permissions(): array
    {
        return ['media.edit'];
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
            ...parent::rules(),
            'target' => ['present', 'nullable', 'string', 'max:'.MediaFolderPath::MAX, new SafeFolderPath],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'target.present' => 'Укажите папку, в которую переместить файлы',
            'target.max' => 'Путь к папке не должен превышать :max символов',
        ];
    }
}
