<?php

namespace App\Jobs;

use App\Models\Media;
use App\Services\ImageOptimizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Job для асинхронной загрузки файла в медиа-библиотеку.
 *
 * Принимает путь к временному файлу, перемещает его в storage/public/media
 * и создаёт запись Media с метаданными (mime, тип, размер, исходное имя).
 *
 * Изображения оптимизируются через ImageOptimizer (WebP + thumbnail).
 * Остальные типы (видео/аудио/документы) сохраняются как есть, без thumbnail.
 *
 * Временный файл сохраняется в storage/app/temp при обработке запроса
 * в AdminMediaController::store(), затем этот Job ставится в очередь.
 *
 * Надёжность: задача закалена для очереди ($tries/$timeout/$backoff ниже). Temp
 * удаляется только после успеха — повтор переобработает файл заново; создание
 * строки идемпотентно (если temp уже убран — задача делает no-op, без дубликата).
 * Прод-воркер: queue:work с супервизированием (см. docker-compose.yml).
 *
 * Использование:
 *   $tempPath = $file->store('temp', 'local');
 *   UploadMedia::dispatch($tempPath, $file->getClientOriginalName());
 */
class UploadMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Каталог назначения на диске public. */
    private const DIRECTORY = 'media';

    /**
     * Жёсткий лимит на одну попытку (сек). Должен быть строго меньше
     * queue retry_after (90с, config/queue.php).
     */
    public int $timeout = 60;

    /**
     * Сколько раз пытаться обработать файл перед уходом в failed_jobs.
     */
    public int $tries = 3;

    /**
     * Возрастающая пауза между повторами (сек) — на случай временного сбоя
     * (недоступность диска/БД).
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * @param  string  $tempPath  Путь к временному файлу относительно диска local
     *                            (например: temp/randomname.jpg)
     * @param  string|null  $originalName  Исходное имя файла при загрузке
     * @param  int|null  $uploaderId  ID загрузившего пользователя (для created_by; null из CLI/вне сессии)
     */
    public function __construct(
        protected string $tempPath,
        protected ?string $originalName = null,
        protected ?int $uploaderId = null,
    ) {}

    /**
     * Выполнение задачи.
     *
     * 1. Определяет MIME-тип временного файла
     * 2. Изображение → оптимизирует (WebP + thumbnail); иначе → копирует как есть
     * 3. Создаёт запись Media с метаданными
     * 4. Удаляет временный файл (только после успеха — см. ниже)
     *
     * temp удаляется последним шагом, только при
     * успехе. На сбое temp остаётся, и повтор переобрабатывает его заново. Если
     * temp уже нет (обработан предыдущей попыткой) — задача делает no-op, не
     * создавая дублирующую строку. Создание строки — последняя операция, способная
     * бросить исключение, поэтому повтор после её успеха невозможен.
     */
    public function handle(): void
    {
        $localDisk = Storage::disk('local');
        $fullSource = $localDisk->path($this->tempPath);

        if (! file_exists($fullSource)) {
            return; // temp уже обработан/убран — идемпотентный no-op
        }

        $mime = mime_content_type($fullSource) ?: null;

        if ($mime !== null && str_starts_with($mime, 'image/')) {
            $filename = ImageOptimizer::storeFromDisk(
                sourcePath: $this->tempPath,
                sourceDisk: 'local',
                directory: self::DIRECTORY,
            );
        } else {
            $filename = $this->copyAsIs();
        }

        $size = Storage::disk('public')->exists($filename)
            ? Storage::disk('public')->size($filename)
            : null;

        $thumbPath = ImageOptimizer::thumbPath($filename);
        $hasThumb = $thumbPath !== $filename && Storage::disk('public')->exists($thumbPath);

        $media = new Media([
            'filename' => $filename,
            'original_name' => $this->originalName,
            'mime_type' => $mime,
            'type' => Media::categorize($mime),
            'size' => $size,
            'has_thumb' => $hasThumb,
        ]);

        if ($this->uploaderId) {
            $media->created_by = $this->uploaderId;
        }

        $media->save();

        $localDisk->delete($this->tempPath);
    }

    /**
     * Копирует файл на диск public без обработки, сохраняя расширение.
     *
     * @return string Имя нового файла относительно диска public
     */
    private function copyAsIs(): string
    {
        $ext = pathinfo($this->tempPath, PATHINFO_EXTENSION);

        $filename = self::DIRECTORY.'/'.Str::random(32).($ext ? '.'.$ext : '');

        $stream = Storage::disk('local')->readStream($this->tempPath);
        Storage::disk('public')->writeStream($filename, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $filename;
    }

    /**
     * Финальная чистка temp-файла после того, как все retry закончились неудачей.
     */
    public function failed(?\Throwable $e = null): void
    {
        Storage::disk('local')->delete($this->tempPath);
    }
}
