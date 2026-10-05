<?php

namespace App\Services;

use App\Jobs\UploadMedia;
use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Support\MediaFolderPath;
use App\Support\MediaUsage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Media library orchestration: queueing uploads and deleting records along with
 * their storage files.
 *
 * Extracted from the controller because deletion is a multi-step operation
 * (a DB transaction + non-transactional file cleanup), not trivial CRUD.
 * Files still referenced somewhere (MediaUsage::references, checked against a
 * freshly built usage index) are never deleted: foreign keys would silently
 * drop the reference and text links would 404.
 *
 * A file change (crop, replacement in UploadMedia) follows one order: new
 * files are written, the record and every link are switched in one
 * transaction, and only after the commit are the old files removed. A failure
 * rolls the switch back and removes the new files instead.
 */
class MediaService
{
    public function __construct(
        private readonly MediaUsage $usage,
        private readonly MediaReferenceUpdater $references,
    ) {}

    /**
     * Queues each file for processing (the UploadMedia job).
     *
     * @param  array<int, UploadedFile>  $files
     * @param  string|null  $batch  Upload request id; the progress poll finds the results by it
     * @return int How many files were queued
     */
    public function queue(array $files, ?int $userId, ?string $batch = null, ?string $folder = null): int
    {
        foreach ($files as $file) {
            $tempPath = $file->store('temp', 'local');
            UploadMedia::dispatch($tempPath, $file->getClientOriginalName(), $userId, null, $batch, $folder);
        }

        return count($files);
    }

    /**
     * Queues a new file for an existing record (the UploadMedia job in replace
     * mode updates the row and removes the old files).
     */
    public function queueReplacement(Media $media, UploadedFile $file, ?int $userId, ?string $batch = null): void
    {
        $tempPath = $file->store('temp', 'local');

        UploadMedia::dispatch($tempPath, $file->getClientOriginalName(), $userId, $media->id, $batch);
    }

    /**
     * Crops an image to a normalized area. The result is stored as a new file
     * (with its own thumbnail and responsive copies), the record points at it,
     * links follow it, and the old files are removed after the commit; the
     * change is logged as media_cropped.
     *
     * @param  array{x: float, y: float, width: float, height: float}  $area
     *
     * @throws ValidationException When the file is not an image that can be processed.
     */
    public function crop(Media $media, array $area): Media
    {
        if (! $media->isImage()) {
            throw ValidationException::withMessages(['crop' => 'Обрезать можно только изображения']);
        }

        try {
            ['filename' => $filename, 'variants' => $variants] = ImageOptimizer::crop($media->filename, $area);
        } catch (\RuntimeException $e) {
            report($e);

            throw ValidationException::withMessages(['crop' => $e->getMessage()]);
        }

        $old = new Media(['filename' => $media->filename, 'variants' => $media->variants]);
        $dimensions = ImageOptimizer::dimensions($filename);
        $disk = Storage::disk(Media::diskName());

        try {
            DB::transaction(function () use ($media, $old, $area, $filename, $variants, $dimensions, $disk) {
                // Without model events: media_cropped below is the single log entry.
                Media::withoutEvents(fn () => $media->forceFill([
                    'filename' => $filename,
                    'mime_type' => str_ends_with($filename, '.avif') ? 'image/avif' : 'image/webp',
                    'size' => $disk->size($filename),
                    'width' => $dimensions['width'] ?? null,
                    'height' => $dimensions['height'] ?? null,
                    'has_thumb' => $disk->exists(ImageOptimizer::thumbPath($filename)),
                    'variants' => $variants === [] ? null : $variants,
                    'focal_x' => null,
                    'focal_y' => null,
                    'updated_by' => Auth::id() ?? $media->updated_by,
                ])->save());

                $links = $this->references->rewrite($old, $media);

                ActivityLog::record($media, 'media_cropped', [
                    'crop' => [null, sprintf('x %.3f, y %.3f, %.3f × %.3f', $area['x'], $area['y'], $area['width'], $area['height'])],
                    'width' => [null, $media->width],
                    'height' => [null, $media->height],
                    ...($links > 0 ? ['links_updated' => [null, $links]] : []),
                ]);
            });
        } catch (\Throwable $e) {
            // Rolled back: the record and links still point at the old files.
            (new Media(['filename' => $filename, 'variants' => $variants]))->deleteFiles();

            throw $e;
        }

        DB::afterCommit(fn () => $old->deleteFiles());

        return $media;
    }

    /**
     * Creates an empty folder at the root or inside $parent.
     */
    public function createFolder(?string $parent, string $name): MediaFolder
    {
        $path = MediaFolderPath::join($parent, $name);
        MediaFolder::register($parent);
        $folder = MediaFolder::create(['name' => $path, 'created_by' => Auth::id()]);

        ActivityLog::record(null, 'folder_created', ['folder' => [null, $path]], $path);

        return $folder;
    }

    /**
     * Renames a folder in place: $from "A/B" with $name "C" becomes "A/C". Its
     * subfolders and files follow; when the target already exists the two
     * merge. One summary log entry instead of one per file.
     *
     * @return int How many files moved (the subfolders' files included)
     */
    public function renameFolder(string $from, string $name): int
    {
        $to = MediaFolderPath::join(MediaFolderPath::parent($from), $name);

        if ($from === $to) {
            return 0;
        }

        $count = DB::transaction(function () use ($from, $to) {
            $rebase = fn (string $path) => $to.mb_substr($path, mb_strlen($from));

            // Shortest paths first, so a parent moves before its subfolders.
            $folders = MediaFolderPath::scope(MediaFolder::query(), 'name', $from)->get()
                ->sortBy(fn (MediaFolder $f) => mb_strlen($f->name));
            foreach ($folders as $folder) {
                $path = $rebase($folder->name);
                $taken = MediaFolder::where('name', $path)->whereKeyNot($folder->id)->exists();
                $taken ? $folder->delete() : $folder->update(['name' => $path]);
            }
            MediaFolder::register($to);

            $moved = 0;
            $paths = MediaFolderPath::scope(Media::query(), 'folder', $from)->distinct()->pluck('folder');
            foreach ($paths as $path) {
                $moved += Media::where('folder', $path)->update(['folder' => $rebase($path)]);
            }

            return $moved;
        });

        ActivityLog::record(null, 'folder_renamed', ['folder' => [$from, $to], 'files' => [null, $count]], $from);

        return $count;
    }

    /**
     * Deletes a folder with its subfolders. Their files are kept: they move up
     * into the parent folder (outside any folder for a top-level one).
     *
     * @return int How many files left the folder
     */
    public function clearFolder(string $folder): int
    {
        $parent = MediaFolderPath::parent($folder);

        $count = DB::transaction(function () use ($folder, $parent) {
            MediaFolderPath::scope(MediaFolder::query(), 'name', $folder)->delete();

            return MediaFolderPath::scope(Media::query(), 'folder', $folder)->update(['folder' => $parent]);
        });

        ActivityLog::record(null, 'folder_cleared', ['folder' => [$folder, $parent], 'files' => [null, $count]], $folder);

        return $count;
    }

    /**
     * Deletes a single record and its associated files (original + thumbnail).
     *
     * @throws ValidationException When the file is still referenced.
     */
    public function delete(Media $media): void
    {
        $references = $this->usage->references([$media], fresh: true);
        if (($references[$media->id] ?? []) !== []) {
            $places = $this->usage->resolve($references)[$media->id];

            throw ValidationException::withMessages(['media' => 'Файл используется: '.$this->describe($places).'. Сначала уберите его оттуда.']);
        }

        $media->delete();
        $media->deleteFiles();
    }

    /**
     * Bulk delete: records in a transaction; files are removed after commit,
     * best-effort (storage is not transactional, deleteFiles tolerates a missing file).
     * Referenced files are skipped; usage is checked for the whole set at once
     * against a freshly built index.
     *
     * @param  array<int, int>  $ids
     * @return array{deleted: int, skipped: list<string>} Deleted count and names of skipped files
     */
    public function bulkDelete(array $ids): array
    {
        /** @var Collection<int, Media> $candidates */
        $candidates = Media::whereIn('id', $ids)->get();
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
            $media->deleteFiles();
        }

        return ['deleted' => $medias->count(), 'skipped' => $skipped];
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

    /**
     * Moves records into a folder (null removes them from any folder). Models are
     * updated one by one so LogsActivity records each change; rows already in the
     * target folder are skipped.
     *
     * @param  array<int, int>  $ids
     * @return int How many records actually changed
     */
    public function moveToFolder(array $ids, ?string $folder): int
    {
        return DB::transaction(function () use ($ids, $folder) {
            $changed = 0;

            Media::whereIn('id', $ids)->get()->each(function (Media $media) use ($folder, &$changed) {
                $media->folder = $folder;
                if ($media->isDirty('folder')) {
                    $media->save();
                    $changed++;
                }
            });

            return $changed;
        });
    }
}
