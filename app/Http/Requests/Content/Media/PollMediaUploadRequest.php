<?php

namespace App\Http\Requests\Content\Media;

use Illuminate\Foundation\Http\FormRequest;

/** Progress of one upload request by its batch id. */
class PollMediaUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('media.view') === true;
    }

    public function rules(): array
    {
        return [
            'batch' => ['required', 'uuid'],
        ];
    }
}
