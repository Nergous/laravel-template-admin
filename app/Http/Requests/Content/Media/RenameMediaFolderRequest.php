<?php

namespace App\Http\Requests\Content\Media;

use App\Support\Media\MediaFolderPath;
use Illuminate\Validation\Validator;

/**
 * Renames a folder in place: name becomes the new last part of its path
 * (merging into an existing folder is allowed). open=1 when the currently
 * open folder is renamed: it then reopens at the new path.
 */
class RenameMediaFolderRequest extends MediaFolderRequest
{
    public function rules(): array
    {
        return [
            'folder' => ['required', ...$this->existingFolderRules()],
            'name' => $this->nameRules(),
            'open' => ['sometimes', 'boolean'],
        ];
    }

    /** The new path must fit. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->targetPath($validator, MediaFolderPath::parent((string) $this->input('folder')));
        });
    }

    public function opensRenamed(): bool
    {
        return (bool) $this->validated('open', false);
    }
}
