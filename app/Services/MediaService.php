<?php

namespace App\Services;

use App\Jobs\UploadMedia;
use App\Models\Media;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Оркестрация медиатеки: постановка загрузок в очередь и удаление записей вместе
 * с файлами хранилища.
 *
 * Вынесено из контроллера, потому что удаление — это многошаговая операция
 * (транзакция БД + нетранзакционная очистка файлов), а не тривиальный CRUD.
 * Граница «когда сервис, когда контроллер» описана в CLAUDE.md.
 */
class MediaService
{
    /**
     * Ставит каждый файл в очередь на обработку (Job UploadMedia).
     *
     * @param  array<int, UploadedFile>  $files
     * @return int Сколько файлов поставлено в очередь
     */
    public function queue(array $files, ?int $userId): int
    {
        foreach ($files as $file) {
            $tempPath = $file->store('temp', 'local');
            UploadMedia::dispatch($tempPath, $file->getClientOriginalName(), $userId);
        }

        return count($files);
    }

    /**
     * Удаляет одну запись и связанные файлы (оригинал + превью).
     */
    public function delete(Media $media): void
    {
        $media->delete();
        $media->deleteFiles();
    }

    /**
     * Массовое удаление: записи — в транзакции; файлы убираются после коммита,
     * best-effort (хранилище не транзакционно, deleteFiles терпим к отсутствию файла).
     *
     * @param  array<int, int>  $ids
     * @return int Сколько записей удалено
     */
    public function bulkDelete(array $ids): int
    {
        /** @var Collection<int, Media> $medias */
        $medias = Media::whereIn('id', $ids)->get();

        DB::transaction(function () use ($medias) {
            foreach ($medias as $media) {
                $media->delete();
            }
        });

        foreach ($medias as $media) {
            $media->deleteFiles();
        }

        return $medias->count();
    }
}
