<?php

namespace App\Http\Requests\Content\Media;

/** Deleting a folder with its subfolders; their files move one level up. */
class DeleteMediaFolderRequest extends MediaFolderRequest
{
    public function rules(): array
    {
        return [
            'folder' => ['required', ...$this->existingFolderRules()],
        ];
    }
}
