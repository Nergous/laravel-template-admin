<?php

namespace App\Http\Requests;

use App\Services\ImageOptimizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Form Request for uploading files to the media library.
 *
 * Used in AdminMediaController::store().
 * After validation, files are queued via the UploadMedia Job.
 *
 * Accepts images, video, audio and documents (see ALLOWED_EXTENSIONS).
 * A thumbnail is generated only for images (in UploadMedia).
 */
class MediaRequest extends FormRequest
{
    /** Allowed file extensions for the media library. */
    public const ALLOWED_EXTENSIONS = [
        // images
        'jpg', 'jpeg', 'png', 'webp', 'gif',
        // video
        'mp4', 'webm', 'mov',
        // audio
        'mp3', 'wav', 'ogg',
        // documents
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt',
    ];

    /** Maximum size of a single file in kilobytes (~50 MB). */
    public const MAX_SIZE_KB = 51200;

    public function authorize(): bool
    {
        return $this->user()?->can('media.upload') === true;
    }

    /**
     * Validation rules:
     * - media     — required array of 1 to 10 files
     * - media.*   — each file must be of an allowed format
     *               (image/video/audio/document) no larger than 50 MB
     */
    public function rules(): array
    {
        $extensions = implode(',', self::ALLOWED_EXTENSIONS);

        return [
            'media' => ['required', 'array', 'min:1', 'max:10'],
            'media.*' => [
                'required',
                'file',
                'mimes:'.$extensions,
                'max:'.self::MAX_SIZE_KB,
                $this->imageWithinPixelLimit(...),
            ],
        ];
    }

    /**
     * Rule: for images, limit the dimensions in pixels.
     *
     * A size limit (max) does not protect against a decompression bomb — a file can be
     * small in size but huge in resolution and expand to gigabytes during
     * GD decoding in the worker (see ImageOptimizer::MAX_PIXELS). We reject such files
     * at the boundary so they never reach the queue. Non-images are skipped.
     */
    protected function imageWithinPixelLimit(string $attribute, mixed $value, \Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $mime = $value->getMimeType();
        if (! is_string($mime) || ! str_starts_with($mime, 'image/')) {
            return;
        }

        $info = @getimagesize($value->getPathname());
        if ($info === false) {
            return;
        }

        if ($info[0] * $info[1] > ImageOptimizer::MAX_PIXELS) {
            $fail('Слишком большое разрешение изображения (макс. '.intdiv(ImageOptimizer::MAX_PIXELS, 1_000_000).' Мп)');
        }
    }

    public function messages(): array
    {
        return [
            'media.required' => 'Выберите хотя бы один файл',
            'media.min' => 'Выберите хотя бы один файл',
            'media.max' => 'Можно загрузить не более 10 файлов за раз',
            'media.*.file' => 'Не удалось загрузить файл',
            'media.*.mimes' => 'Недопустимый формат файла',
            'media.*.max' => 'Размер файла не должен превышать 50 МБ',
        ];
    }
}
