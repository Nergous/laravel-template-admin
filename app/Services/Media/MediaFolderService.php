<?php

namespace App\Services\Media;

use App\Events\ActivityOccurred;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\User;
use App\Support\Media\MediaFolderPath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Media library folders: create, rename and delete. A folder is a path
 * ("Banners/2026"); on rename its subfolders and files follow it, on delete
 * the files move one level up. Every operation writes one summary log entry,
 * not one per file. Moving files between folders lives in
 * MediaService::moveToFolder().
 */
class MediaFolderService
{
    /**
     * Ensures the folder and every folder above it exist. New rows get $actor
     * as their author (null — no author: the queue or the safety net in
     * MediaObserver).
     */
    public function register(?string $path, ?User $actor = null): void
    {
        if ($path === null || $path === '') {
            return;
        }

        foreach (MediaFolderPath::lineage($path) as $folder) {
            MediaFolder::query()->firstOrCreate(['name' => $folder], ['created_by' => $actor?->id]);
        }
    }

    /**
     * Creates an empty folder at the root or inside $parent.
     *
     * @throws ValidationException When such a folder already exists.
     */
    public function create(?string $parent, string $name, User $actor): MediaFolder
    {
        $path = MediaFolderPath::join($parent, $name);

        if (MediaFolder::where('name', $path)->exists()) {
            throw ValidationException::withMessages(['name' => 'Папка с таким названием уже есть']);
        }

        $this->register($parent, $actor);
        $folder = MediaFolder::create(['name' => $path, 'created_by' => $actor->id]);

        ActivityOccurred::by($actor, null, 'folder_created', ['folder' => [null, $path]], $path);

        return $folder;
    }

    /**
     * Renames a folder in place: $from "A/B" with $name "C" becomes "A/C".
     * Subfolders and files follow it; if such a folder already exists, they merge.
     *
     * @return array{path: string, moved: int} The new path and how many files moved (including subfolder files)
     */
    public function rename(string $from, string $name, User $actor): array
    {
        $to = MediaFolderPath::join(MediaFolderPath::parent($from), $name);

        if ($from === $to) {
            return ['path' => $to, 'moved' => 0];
        }

        $moved = DB::transaction(function () use ($from, $to, $actor) {
            $rebase = fn (string $path) => $to.mb_substr($path, mb_strlen($from));

            // Shortest paths first, so a parent moves before its subfolders.
            $folders = $this->inFolder(MediaFolder::query(), 'name', $from)->get()
                ->sortBy(fn (MediaFolder $f) => mb_strlen($f->name));
            foreach ($folders as $folder) {
                $path = $rebase($folder->name);
                $taken = MediaFolder::where('name', $path)->whereKeyNot($folder->id)->exists();
                $taken ? $folder->delete() : $folder->update(['name' => $path]);
            }
            $this->register($to, $actor);

            $moved = 0;
            $paths = $this->inFolder(Media::query(), 'folder', $from)->distinct()->pluck('folder');
            foreach ($paths as $path) {
                $moved += Media::where('folder', $path)->update(['folder' => $rebase($path)]);
            }

            return $moved;
        });

        ActivityOccurred::by($actor, null, 'folder_renamed', ['folder' => [$from, $to], 'files' => [null, $moved]], $from);

        return ['path' => $to, 'moved' => $moved];
    }

    /**
     * Deletes a folder with its subfolders. Their files are kept: they move to
     * the parent folder (outside any folder for a top-level folder).
     *
     * @return int How many files left the folder
     */
    public function delete(string $folder, User $actor): int
    {
        $parent = MediaFolderPath::parent($folder);

        $count = DB::transaction(function () use ($folder, $parent) {
            $this->inFolder(MediaFolder::query(), 'name', $folder)->delete();

            return $this->inFolder(Media::query(), 'folder', $folder)->update(['folder' => $parent]);
        });

        ActivityOccurred::by($actor, null, 'folder_cleared', ['folder' => [$folder, $parent], 'files' => [null, $count]], $folder);

        return $count;
    }

    /**
     * Narrows the query to the folder and everything inside it. substr() compares
     * names with % and _ literally, unlike LIKE.
     *
     * @template TModel of Media|MediaFolder
     *
     * @param  Builder<TModel>  $query
     * @param  'name'|'folder'  $column  Column holding the folder path
     * @return Builder<TModel>
     */
    private function inFolder(Builder $query, string $column, string $folder): Builder
    {
        $prefix = $folder.'/';

        return $query->where(fn (Builder $q) => $q
            ->where($column, $folder)
            ->orWhereRaw("substr({$column}, 1, ?) = ?", [mb_strlen($prefix), $prefix]));
    }
}
