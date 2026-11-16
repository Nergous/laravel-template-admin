<?php

namespace App\Services\Media;

use App\Exceptions\MediaInUseException;
use App\Models\Media;
use App\Models\User;
use App\Support\Media\MediaFiles;
use App\Support\Selection;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Media library changes: editing file details, moving between folders and
 * deleting records together with their files. Uploads are queued by
 * MediaUploads, cropping is done by MediaCropper.
 *
 * Deletion is a multi-step operation (a DB transaction plus non-transactional
 * file cleanup). Files that are still referenced (MediaUsage::references,
 * checked against a fresh index) are never deleted: foreign keys would
 * silently lose their target and text links would lead to a 404.
 */
class MediaService
{
    public function __construct(
        private readonly MediaUsage $usage,
        private readonly MediaPlaces $places,
        private readonly MediaStorage $storage,
        private readonly MediaLibrary $library,
        private readonly MediaFolderService $folders,
    ) {}

    /**
     * Image dimensions: stored ones, or read from the file header for records
     * saved before these columns existed. Null for other files.
     *
     * @return array{width: int, height: int}|null
     */
    public function dimensions(Media $media): ?array
    {
        if ($media->width !== null && $media->height !== null) {
            return ['width' => $media->width, 'height' => $media->height];
        }

        return $media->isImage() ? $this->storage->dimensions($media->filename) : null;
    }

    /**
     * Updates the editable details: display name, alt text, folder and focal
     * point. The stored filename does not change, so links and thumbnails keep
     * working; a new folder is registered on behalf of the actor, LogsActivity
     * logs the change.
     *
     * @param  array<string, mixed>  $data  Validated UpdateMediaRequest data
     */
    public function update(Media $media, array $data, User $actor): Media
    {
        return DB::transaction(function () use ($media, $data, $actor) {
            if (array_key_exists('folder', $data)) {
                $this->folders->register($data['folder'], $actor);
            }

            $media->update($data);

            return $media;
        });
    }

    /**
     * Deletes one record and its files (original, thumbnail and copies).
     *
     * @throws MediaInUseException When the file is still in use.
     */
    public function delete(Media $media): void
    {
        $references = $this->usage->references([$media], fresh: true);
        if (($references[$media->id] ?? []) !== []) {
            $places = $this->places->resolve($references)[$media->id];

            throw new MediaInUseException('Файл используется: '.$this->describe($places).'. Сначала уберите его оттуда.');
        }

        $media->delete();
        $this->storage->delete(MediaFiles::of($media));
    }

    /**
     * Bulk delete: records in a transaction, files after it on a best-effort
     * basis (storage is not transactional, a missing file is skipped).
     * Files in use are skipped; usage is checked for the whole set at once
     * against a fresh index.
     *
     * @return array{deleted: int, skipped: list<string>, warning: ?string} How many were deleted, names of the skipped files and a warning about them
     */
    public function bulkDelete(Selection $selection): array
    {
        /** @var Collection<int, Media> $candidates */
        $candidates = $selection->query(Media::query(), $this->library->filter(...))->get();
        $references = $this->usage->references($candidates, fresh: true);
        $skipped = [];
        $medias = $candidates->filter(function (Media $media) use ($references, &$skipped) {
            if (($references[$media->id] ?? []) === []) {
                return true;
            }

            $skipped[] = (string) ($media->original_name ?: basename($media->filename));

            return false;
        });

        DB::transaction(function () use ($medias) {
            foreach ($medias as $media) {
                $media->delete();
            }
        });

        foreach ($medias as $media) {
            $this->storage->delete(MediaFiles::of($media));
        }

        return ['deleted' => $medias->count(), 'skipped' => $skipped, 'warning' => $this->skippedWarning($skipped)];
    }

    /**
     * Moves the selected files to a folder (null removes them from folders);
     * missing folders are created on behalf of the actor. Models are updated one
     * by one so LogsActivity records every change; files already in the target
     * folder are skipped.
     *
     * @return int How many files were actually moved
     */
    public function moveToFolder(Selection $selection, ?string $folder, User $actor): int
    {
        $this->folders->register($folder, $actor);

        return $selection->each(Media::query(), $this->library->filter(...), function (Media $media) use ($folder) {
            $media->folder = $folder;
            if (! $media->isDirty('folder')) {
                return null;
            }

            $media->save();

            return true;
        })['processed'];
    }

    /** @param  list<string>  $skipped  Names of files kept because they are in use */
    private function skippedWarning(array $skipped): ?string
    {
        if ($skipped === []) {
            return null;
        }

        $names = implode(', ', array_slice($skipped, 0, 5)).(count($skipped) > 5 ? ' и др.' : '');

        return 'Не удалены используемые файлы ('.count($skipped)."): {$names}";
    }

    /** @param list<array{label: string, title: ?string, url: ?string}> $places */
    private function describe(array $places): string
    {
        if ($places === []) {
            return 'в материалах сайта';
        }

        $parts = array_map(fn (array $place) => $place['label'].($place['title'] ? ' «'.$place['title'].'»' : ''), array_slice($places, 0, 3));
        $more = count($places) - count($parts);

        return implode(', ', $parts).($more > 0 ? " и ещё {$more}" : '');
    }
}
