<?php

namespace App\Http\Requests;

use App\Services\ImageOptimizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Form Request для загрузки файлов в медиа-библиотеку.
 *
 * Используется в AdminMediaController::store().
 * Файлы после валидации помещаются в очередь через UploadMedia Job.
 *
 * Принимает изображения, видео, аудио и документы (см. ALLOWED_EXTENSIONS).
 * Thumbnail генерируется только для изображений (в UploadMedia).
 */
class MediaRequest extends FormRequest
{
    /** Допустимые расширения файлов медиа-библиотеки. */
    public const ALLOWED_EXTENSIONS = [
        // изображения
        'jpg', 'jpeg', 'png', 'webp', 'gif',
        // видео
        'mp4', 'webm', 'mov',
        // аудио
        'mp3', 'wav', 'ogg',
        // документы
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt',
    ];

    /** Максимальный размер одного файла в килобайтах (~50 МБ). */
    public const MAX_SIZE_KB = 51200;

    public function authorize(): bool
    {
        return $this->user()?->can('media.upload') === true;
    }

    /**
     * Правила валидации:
     * - media     — обязательный массив от 1 до 10 файлов
     * - media.*   — каждый файл должен быть допустимого формата
     *               (изображение/видео/аудио/документ) не более 50 МБ
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
     * Правило: для изображений ограничиваем размерность в пикселях.
     *
     * Лимит по весу (max) не спасает от decompression bomb — файл может быть мал
     * по весу, но огромен по разрешению и развернуться в гигабайты при
     * GD-декодировании в воркере (см. ImageOptimizer::MAX_PIXELS). Отклоняем такие
     * на границе, чтобы они не доходили до очереди. Не-изображения пропускаем.
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
