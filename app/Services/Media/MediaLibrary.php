<?php

namespace App\Services\Media;

use App\Enums\MediaType;
use App\Enums\MediaUsageFilter;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Support\Media\MediaFilters;
use App\Support\Media\MediaFolderPath;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Reading the media library: the filtered list, the folder tree with stats and
 * the filter for bulk actions. Writes live in MediaService, uploads and their
 * progress in MediaUploads.
 */
class MediaLibrary
{
    /** Files per media picker page (fixed: the picker has no page size switch). */
    public const PICKER_PAGE_SIZE = 24;

    public function __construct(private readonly MediaUsage $usage) {}

    /**
     * How many files the library holds and how much space they take (dashboard and backups page).
     *
     * @return array{count: int, bytes: int}
     */
    public function totals(): array
    {
        $row = Media::query()->toBase()->selectRaw('count(*) as files, coalesce(sum(size), 0) as bytes')->first();

        return ['count' => (int) ($row->files ?? 0), 'bytes' => (int) ($row->bytes ?? 0)];
    }

    /**
     * A list page with every filter applied; equal values are ordered by id.
     *
     * @return LengthAwarePaginator<int, Media>
     */
    public function paginate(MediaFilters $filters, string $sort, string $direction, int $perPage): LengthAwarePaginator
    {
        return $this->matching(Media::query(), $filters)
            ->orderBy($sort, $direction)
            ->orderBy('id', $direction)
            ->paginate($perPage);
    }

    /**
     * File counts per type (type switch labels): every filter except the type.
     *
     * @return array<string, int>
     */
    public function typeCounts(MediaFilters $filters): array
    {
        return $this->scope(Media::query(), $filters)
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    /**
     * Selection filter for bulk actions (Selection): with all=1 the action
     * applies to every file the list shows for the filters (on all pages,
     * without folders).
     *
     * @param  Builder<Media>  $query
     * @param  array<string, mixed>  $filters  List filters: search, type, folder, usage
     * @return Builder<Media>
     */
    public function filter(Builder $query, array $filters): Builder
    {
        return $this->matching($query, MediaFilters::fromInput($filters));
    }

    /**
     * The folder itself when it exists; for a stale link to a renamed or deleted
     * folder — the nearest existing folder above it, or null for the root.
     */
    public function nearestExistingFolder(string $folder): ?string
    {
        if (MediaFolder::where('name', $folder)->exists()) {
            return $folder;
        }

        $lineage = MediaFolderPath::lineage($folder);
        $existing = MediaFolder::whereIn('name', $lineage)->pluck('name')->all();

        return collect(array_reverse($lineage))
            ->first(fn (string $path) => in_array($path, $existing, true));
    }

    /**
     * Every folder by path: what lies directly inside (files, subfolders), plus
     * the file count and size of the whole subtree.
     *
     * @return list<array{folder: MediaFolder, count: int, subfolders: int, total: int, size: int}>
     */
    public function folders(): array
    {
        // Lower-case keys: MySQL compares folder names case-insensitively.
        $stats = [];
        Media::query()
            ->whereNotNull('folder')
            ->selectRaw('folder, count(*) as files, coalesce(sum(size), 0) as bytes')
            ->groupBy('folder')
            ->get()
            ->each(function (Media $row) use (&$stats) {
                $key = mb_strtolower((string) $row->folder);
                $stats[$key]['count'] = ($stats[$key]['count'] ?? 0) + (int) $row->getAttribute('files');
                $stats[$key]['size'] = ($stats[$key]['size'] ?? 0) + (int) $row->getAttribute('bytes');
            });

        $folders = MediaFolder::query()->orderBy('name')->get();
        $children = [];
        foreach ($folders as $f) {
            $parent = MediaFolderPath::parent($f->name);
            if ($parent !== null) {
                $key = mb_strtolower($parent);
                $children[$key] = ($children[$key] ?? 0) + 1;
            }
        }

        return $folders->map(function (MediaFolder $f) use ($stats, $children) {
            $key = mb_strtolower($f->name);
            $total = 0;
            $size = 0;
            foreach ($stats as $path => $stat) {
                if ($path === $key || str_starts_with($path, $key.'/')) {
                    $total += $stat['count'];
                    $size += $stat['size'];
                }
            }

            return [
                'folder' => $f,
                'count' => $stats[$key]['count'] ?? 0,
                'subfolders' => $children[$key] ?? 0,
                'total' => $total,
                'size' => $size,
            ];
        })->all();
    }

    /**
     * Media picker page: newest first, PICKER_PAGE_SIZE per page.
     *
     * @return LengthAwarePaginator<int, Media>
     */
    public function pickerPage(?string $search, ?MediaType $type, int $page): LengthAwarePaginator
    {
        return Media::query()
            ->when($search, fn ($q, $term) => $q->search($term))
            ->when($type, fn ($q, MediaType $t) => $q->where('type', $t))
            ->orderByDesc('id')
            ->paginate(self::PICKER_PAGE_SIZE, ['*'], 'page', $page);
    }

    /** List files with every filter applied. */
    private function matching(Builder $query, MediaFilters $filters): Builder
    {
        return $this->scope($query, $filters)
            ->when($filters->type, fn ($q, MediaType $type) => $q->where('type', $type));
    }

    /**
     * List files with every filter except the type (the per-type counts use it).
     * A level shows its own files; search and the usage filter at the root
     * search the whole library.
     */
    private function scope(Builder $query, MediaFilters $filters): Builder
    {
        return $query
            ->when($filters->search !== '', fn ($q) => $q->search($filters->search))
            ->when($filters->folder !== null, fn ($q) => $q->where('folder', $filters->folder))
            ->when($filters->folder === null && ! $filters->wholeLibrary(), fn ($q) => $q->whereNull('folder'))
            ->when($filters->usage, fn ($q, MediaUsageFilter $usage) => match ($usage) {
                MediaUsageFilter::Used => $this->usage->constrain($q, true),
                MediaUsageFilter::Unused => $this->usage->constrain($q, false),
                MediaUsageFilter::NoAlt => $q->withoutAlt(),
            });
    }
}
