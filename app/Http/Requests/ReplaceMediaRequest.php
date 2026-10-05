<?php

namespace App\Http\Requests;

use App\Models\Media;
use Illuminate\Http\UploadedFile;

/**
 * Form Request for replacing the file of an existing media record.
 *
 * Accepts one file with the same format, size, and pixel limits as a regular
 * upload (see MediaRequest), of the same type as the current file: an image
 * placed on the site as <img> must stay an image. Gated by media.edit: the
 * record stays, only its file changes.
 */
class ReplaceMediaRequest extends MediaRequest
{
    /** Error per current type when the new file is of another one. */
    private const TYPE_MESSAGES = [
        'image' => 'Изображение можно заменить только изображением',
        'video' => 'Видео можно заменить только видео',
        'audio' => 'Аудио можно заменить только аудио',
        'document' => 'Документ можно заменить только документом',
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
                'mimes:'.implode(',', self::ALLOWED_EXTENSIONS),
                'max:'.self::MAX_SIZE_KB,
                $this->imageWithinPixelLimit(...),
                $this->sameTypeAsCurrent(...),
            ],
        ];
    }

    /**
     * Rule: the new file falls into the same category (Media::categorize) as
     * the record's current file, judged by the detected MIME type like the
     * UploadMedia job does. A record of unknown type (no type, no MIME) is not
     * restricted.
     */
    protected function sameTypeAsCurrent(string $attribute, mixed $value, \Closure $fail): void
    {
        $media = $this->route('media');
        if (! $media instanceof Media || ! $value instanceof UploadedFile) {
            return;
        }

        $current = $media->type ?: ($media->mime_type ? Media::categorize($media->mime_type) : null);
        if ($current !== null && Media::categorize($value->getMimeType()) !== $current) {
            $fail(self::TYPE_MESSAGES[$current] ?? 'Файл можно заменить только файлом того же типа');
        }
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Выберите файл',
            'file.file' => 'Не удалось загрузить файл',
            'file.mimes' => 'Недопустимый формат файла',
            'file.max' => 'Размер файла не должен превышать 50 МБ',
        ];
    }
}
