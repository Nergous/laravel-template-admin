<?php

namespace App\Http\Requests\Content\Media;

use Illuminate\Validation\Validator;

/**
 * Creating a folder: name at the root or inside an existing parent. That no
 * such folder exists yet is checked by MediaFolderService::create().
 */
class StoreMediaFolderRequest extends MediaFolderRequest
{
    public function rules(): array
    {
        return [
            'parent' => ['nullable', ...$this->existingFolderRules()],
            'name' => $this->nameRules(),
        ];
    }

    /** The new path must fit. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->targetPath($validator, $this->input('parent'));
        });
    }
}
