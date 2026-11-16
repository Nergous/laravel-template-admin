<?php

namespace App\Http\Requests\Content\Media;

use App\Enums\MediaType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Search, type and pagination of the media picker (MediaPickerController). */
class MediaPickerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('media.view') === true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', 'string', Rule::enum(MediaType::class)],
        ];
    }

    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }

    /** File kind of the picker; null — all kinds. */
    public function type(): ?MediaType
    {
        return MediaType::tryFrom((string) $this->validated('type'));
    }
}
