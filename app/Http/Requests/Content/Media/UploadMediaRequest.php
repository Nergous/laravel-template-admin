<?php

namespace App\Http\Requests\Content\Media;

use App\Rules\ImageWithinPixelLimit;
use App\Support\Media\MediaFolderPath;
use App\Support\Media\UploadRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Uploading files into the media library (MediaUploadController::store()).
 * After validation the files are queued (the UploadMedia job).
 *
 * Accepts images, video, audio and documents within UploadRules.
 * Only images get a thumbnail (in UploadMedia). AVIF is processed when GD
 * can decode it, otherwise stored as is.
 */
class UploadMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('media.upload') === true;
    }

    /** An empty folder means the library root. */
    protected function prepareForValidation(): void
    {
        if ($this->has('folder')) {
            $folder = trim((string) $this->input('folder'));
            $this->merge(['folder' => $folder === '' ? null : $folder]);
        }
    }

    /**
     * - media    — 1 to UploadRules::MAX_FILES files
     * - media.*  — an allowed format within the size and pixel limits
     * - folder   — an optional existing folder for the files
     */
    public function rules(): array
    {
        return [
            'media' => ['required', 'array', 'min:1', 'max:'.UploadRules::MAX_FILES],
            'media.*' => [
                'required',
                'file',
                'mimes:'.implode(',', UploadRules::extensions()),
                'max:'.UploadRules::MAX_SIZE_KB,
                new ImageWithinPixelLimit,
            ],
            'folder' => ['nullable', 'string', 'max:'.MediaFolderPath::MAX, Rule::exists('media_folders', 'name')],
        ];
    }

    public function messages(): array
    {
        return [
            'media.required' => 'Выберите хотя бы один файл',
            'media.min' => 'Выберите хотя бы один файл',
            'media.max' => 'Можно загрузить не более '.UploadRules::MAX_FILES.' файлов за раз',
            'media.*.file' => 'Не удалось загрузить файл',
            'media.*.mimes' => 'Недопустимый формат файла',
            'media.*.max' => 'Размер файла не должен превышать '.UploadRules::maxSizeLabel(),
            'folder.exists' => 'Папка не найдена — обновите страницу',
        ];
    }
}
