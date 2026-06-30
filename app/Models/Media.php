<?php

namespace App\Models;

use App\Services\ImageOptimizer;
use App\Traits\HasSearch;
use App\Traits\LogsActivity;
use App\Traits\TracksAuthor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Модель медиа из библиотеки.
 *
 * Файлы лежат на диске public в директории media/.
 * Загрузка выполняется асинхронно через Job UploadMedia.
 *
 * Авторство: подключён трейт TracksAuthor (связи creator()/editor()). created_by
 * трейт сам не заполнит — строка Media создаётся в очереди (UploadMedia), где
 * Auth::id() пуст; поэтому id загрузившего проставляется в job вручную из
 * uploaderId. updated_by пишет трейт при редактировании медиа авторизованным
 * пользователем (например переименование файла на фронте).
 *
 * @property int $id
 * @property string $filename Путь к файлу относительно диска public (например: media/abc123.webp)
 * @property string|null $original_name Исходное имя файла при загрузке
 * @property string|null $mime_type MIME-тип (например image/webp, video/mp4)
 * @property string|null $type Категория: image|video|audio|document|other
 * @property int|null $size Размер в байтах
 */
class Media extends Model
{
    use HasSearch, LogsActivity, TracksAuthor;

    protected $fillable = ['filename', 'original_name', 'mime_type', 'type', 'size', 'has_thumb'];

    protected $casts = [
        'size' => 'integer',
        'has_thumb' => 'boolean',
    ];

    /**
     * Публичные URL подмешиваются в сериализацию, чтобы фронт (карточки
     * медиатеки) сразу получал ссылки на оригинал и превью.
     */
    protected $appends = ['url', 'thumb_url'];

    public function getUrlAttribute(): string
    {
        return $this->url();
    }

    public function getThumbUrlAttribute(): string
    {
        return $this->thumbUrl();
    }

    /**
     * Категоризирует MIME-тип в одну из крупных групп.
     * Используется при загрузке для заполнения колонки type.
     *
     * @param  string|null  $mime  MIME-тип файла (например image/png, video/mp4)
     * @return string Категория: image|video|audio|document|other
     */
    public static function categorize(?string $mime): string
    {
        $mime = (string) $mime;

        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            str_starts_with($mime, 'text/') => 'document',
            in_array($mime, [
                'application/pdf',
                'application/msword',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ], true) => 'document',
            default => 'other',
        };
    }

    /**
     * Является ли файл изображением (для него генерируется thumbnail).
     */
    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    /**
     * Публичный URL оригинального файла.
     */
    public function url(): string
    {
        return Storage::url($this->filename);
    }

    /**
     * URL уменьшенной версии (для фото).
     */
    public function thumbUrl(): string
    {
        if (! $this->has_thumb) {
            return $this->url();
        }

        return Storage::url(ImageOptimizer::thumbPath($this->filename));
    }

    /**
     * Удаляет с диска public сам файл и его сгенерированную миниатюру.
     *
     * Единая точка очистки файлов медиа — вызывайте её из любого пути удаления,
     * чтобы не оставлять осиротевших файлов. Для не-изображений (и фолбэка без GD)
     * thumbPath() возвращает сам путь — второй delete тогда пропускаем.
     */
    public function deleteFiles(): void
    {
        $disk = Storage::disk('public');
        $disk->delete($this->filename);

        $thumb = ImageOptimizer::thumbPath($this->filename);
        if ($thumb !== $this->filename) {
            $disk->delete($thumb);
        }
    }

    /**
     * Поиск по имени файла (подстрока).
     *
     * @param  string|null  $search  Поисковая строка
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        return $this->scopeSearchLike($query, $search, ['filename']);
    }
}
