<?php

namespace App\Http\Requests\Content\Media;

use App\Enums\MediaType;
use App\Models\Media;
use App\Rules\ImageWithinPixelLimit;
use App\Support\Media\UploadRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Form Request for replacing the file of an existing media record.
 *
 * Accepts one file with the same format, size and pixel limits as a regular
 * upload (UploadRules), of the same kind as the current file: an image
 * embedded as <img> must stay an image.
 * Requires media.edit: the record stays, only its file changes.
 */
class ReplaceMediaRequest extends FormRequest
{
    /** Error per current kind when the new file is of another kind. */
    private const TYPE_MESSAGES = [
        MediaType::Image->value => 'Изображение можно заменить только изображением',
        MediaType::Video->value => 'Видео можно заменить только видео',
        MediaType::Audio->value => 'Аудио можно заменить только аудио',
        MediaType::Document->value => 'Документ можно заменить только документом',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('media.edit') === true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:'.implode(',', UploadRules::extensions()),
                'max:'.UploadRules::MAX_SIZE_KB,
                new ImageWithinPixelLimit,
                $this->sameTypeAsCurrent(...),
            ],
        ];
    }

    /**
     * Rule: the new file is of the same kind (MediaType::fromMime) as the record's
     * current file, by the detected MIME type — as on upload. A record of an
     * unknown kind (neither kind nor MIME) is not restricted.
     */
    protected function sameTypeAsCurrent(string $attribute, mixed $value, \Closure $fail): void
    {
        $media = $this->route('media');
        if (! $media instanceof Media || ! $value instanceof UploadedFile) {
            return;
        }

        $current = $media->type ?? ($media->mime_type ? MediaType::fromMime($media->mime_type) : null);
        if ($current !== null && MediaType::fromMime($value->getMimeType()) !== $current) {
            $fail(self::TYPE_MESSAGES[$current->value] ?? 'Файл можно заменить только файлом того же типа');
        }
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Выберите файл',
            'file.file' => 'Не удалось загрузить файл',
            'file.mimes' => 'Недопустимый формат файла',
            'file.max' => 'Размер файла не должен превышать '.UploadRules::maxSizeLabel(),
        ];
    }
}
