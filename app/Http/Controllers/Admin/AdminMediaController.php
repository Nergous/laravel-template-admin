<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Pagination\MediaPerPage;
use App\Http\Requests\BulkDestroyMediaRequest;
use App\Http\Requests\BulkMediaFolderRequest;
use App\Http\Requests\CropMediaRequest;
use App\Http\Requests\MediaFolderRequest;
use App\Http\Requests\MediaRequest;
use App\Http\Requests\RenameMediaRequest;
use App\Http\Requests\ReplaceMediaRequest;
use App\Http\Sorts\MediaSort;
use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\MediaFolder;
use App\Providers\SettingsServiceProvider;
use App\Services\ImageOptimizer;
use App\Services\MediaService;
use App\Support\MediaFolderPath;
use App\Support\MediaUsage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing media in the admin panel.
 *
 * Handles HTTP: validates input, assembles Inertia props, and redirects.
 * Orchestration (queueing, transactional deletion + file cleanup)
 * lives in App\Services\MediaService. Read methods (index/poll) stay here.
 */
class AdminMediaController extends Controller
{
    /** Media categories accepted by the type filter. */
    public const TYPES = ['image', 'video', 'audio', 'document', 'other'];

    /**
     * Usage filter values: files referenced somewhere (see MediaUsage) or not,
     * and images without alternative text.
     */
    public const USAGES = ['used', 'unused', 'no_alt'];

    public function __construct(
        private readonly MediaService $media,
        private readonly MediaUsage $usage,
    ) {}

    /**
     * List of media with a type filter, search, sorting, and page size.
     *
     * The library browses like a file manager: a level lists its own files plus
     * its subfolders (taken from the folders prop on the client), ?folder= opens
     * a folder by path, none — the root. Search and the usage filter at the root
     * look through the whole library.
     *
     * Supported sort parameters (GET):
     * - sort (id|original_name|created_at|size|mime_type) — the sort field
     * - direction (asc|desc)               — the sort direction
     * - type (image|video|audio|document|other), search, per_page
     * - folder — the open folder's path ("Баннеры/2026"); empty — the root level
     * - usage (used|unused|no_alt) — whether the file is referenced, or lacks alt
     *
     * typeCounts ignores the type filter (it labels the type switch), but
     * respects search, the open level and usage.
     */
    public function index(Request $request, MediaSort $sort, MediaPerPage $perPage): Response|RedirectResponse
    {
        ['type' => $type, 'usage' => $usage, 'folder' => $folder] = $this->filters($request->query());

        // A stale link to a renamed or deleted folder opens the nearest folder
        // above it that still exists, or the root.
        if ($folder !== null && ! MediaFolder::where('name', $folder)->exists()) {
            $existing = MediaFolder::whereIn('name', MediaFolderPath::lineage($folder))->pluck('name')->all();
            $nearest = collect(array_reverse(MediaFolderPath::lineage($folder)))
                ->first(fn (string $path) => in_array($path, $existing, true));

            return redirect()->route('admin.media.index', array_filter(['folder' => $nearest]));
        }

        $base = $this->filtered($this->filters($request->query()));

        $typeCounts = (clone $base)
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type')
            ->map(fn ($count) => (int) $count)
            ->all();

        $media = (clone $base)
            ->when($type, fn ($q, string $t) => $q->where('type', $t))
            ->orderBy($sort->getSort(), $sort->getDirection())
            ->orderBy('id', $sort->getDirection())
            ->paginate($perPage->get())
            ->withQueryString();

        if ($redirect = $this->redirectPastLastPage($media, $request)) {
            return $redirect;
        }

        $usages = $this->usage->for($media->getCollection());
        $media->getCollection()->each(
            fn (Media $item) => $item->setAttribute('usages', $usages[$item->id] ?? [])
        );

        return Inertia::render('Media/Index', [
            'media' => $media,
            ...$sort->toArray(), // currentSort + currentDirection from the validated Sort
            ...$perPage->toArray(),
            'filters' => [
                'search' => (string) $request->query('search', ''),
                'type' => $type ?? '',
                'folder' => $folder ?? '',
                'usage' => $usage ?? '',
            ],
            'folders' => $this->folders(),
            'typeCounts' => $typeCounts,
            'uploadRules' => [
                'extensions' => MediaRequest::ALLOWED_EXTENSIONS,
                'maxSizeKb' => MediaRequest::MAX_SIZE_KB,
                'maxFiles' => MediaRequest::MAX_FILES,
            ],
        ]);
    }

    /**
     * Normalized list filters from the query string (index) or from the flat
     * filter fields of a bulk "every matching file" request (same names).
     *
     * @param  array<string, mixed>  $input
     * @return array{type: ?string, usage: ?string, search: string, folder: ?string}
     */
    private function filters(array $input): array
    {
        $folder = $input['folder'] ?? null;

        return [
            'type' => in_array($input['type'] ?? null, self::TYPES, true) ? $input['type'] : null,
            'usage' => in_array($input['usage'] ?? null, self::USAGES, true) ? $input['usage'] : null,
            'search' => trim((string) ($input['search'] ?? '')),
            'folder' => is_string($folder) && $folder !== '' && mb_strlen($folder) <= MediaFolderPath::MAX ? $folder : null,
        ];
    }

    /**
     * Files of the list for the given filters, the type filter aside (index
     * counts types over this query). A level lists its own files; search and
     * the usage filter at the root look through the whole library.
     *
     * @param  array{type: ?string, usage: ?string, search: string, folder: ?string}  $filters
     */
    private function filtered(array $filters): Builder
    {
        ['usage' => $usage, 'search' => $search, 'folder' => $folder] = $filters;
        $wholeLibrary = $folder === null && ($search !== '' || $usage !== null);

        return Media::query()
            ->when($search !== '', fn ($q) => $q->search($search))
            ->when($folder !== null, fn ($q) => $q->where('folder', $folder))
            ->when($folder === null && ! $wholeLibrary, fn ($q) => $q->whereNull('folder'))
            ->when(in_array($usage, ['used', 'unused'], true), fn ($q) => $this->usage->constrain($q, $usage === 'used'))
            ->when($usage === 'no_alt', fn ($q) => $q
                ->where('type', 'image')
                ->where(fn ($alt) => $alt->whereNull('alt')->orWhere('alt', '')));
    }

    /**
     * Ids a bulk action applies to: the picked ones, or with all=1 every file
     * the list shows for the sent flat filters search/type/folder/usage
     * (every page, folders aside).
     *
     * @return list<int>
     */
    private function selectedIds(Request $request): array
    {
        if (! $request->boolean('all')) {
            return array_map('intval', $request->input('ids', []));
        }

        $filters = $this->filters($request->only(['search', 'type', 'folder', 'usage']));

        return $this->filtered($filters)
            ->when($filters['type'], fn ($q, string $t) => $q->where('type', $t))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Every folder by path, with what lies directly inside (files, subfolders)
     * and the whole subtree's file count and size.
     *
     * @return list<array{name: string, count: int, subfolders: int, total: int, size: int, created_at: ?string, created_local: ?string}>
     */
    private function folders(): array
    {
        $timezone = SettingsServiceProvider::displayTimezone();
        // Keys are lowercased: MySQL matches folder names case-insensitively.
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

        return $folders->map(function (MediaFolder $f) use ($stats, $children, $timezone) {
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
                'name' => $f->name,
                'count' => $stats[$key]['count'] ?? 0,
                'subfolders' => $children[$key] ?? 0,
                'total' => $total,
                'size' => $size,
                'created_at' => $f->created_at?->toIso8601String(),
                'created_local' => $f->created_at?->timezone($timezone)->format('d.m.Y H:i'),
            ];
        })->all();
    }

    /**
     * Queues files for upload.
     *
     * Files are temporarily saved to storage/app/temp,
     * then a Job moves them to the media disk (into the given folder, if any).
     *
     * @return JsonResponse { "queued": 3, "batch": uuid } — batch feeds poll()
     */
    public function store(MediaRequest $request): JsonResponse
    {
        // Every row and log entry of this request carries the batch id, so the
        // poll never mixes in another upload (even the same user's parallel one).
        $batch = (string) Str::uuid();

        // Q4: take the files once with a default of [] — don't rely on the key
        // being present (count(null) would be a TypeError if the form rules change).
        $queued = $this->media->queue(
            $request->file('media', []),
            $request->user()?->id,
            $batch,
            $request->validated('folder'),
        );

        return response()->json(['queued' => $queued, 'batch' => $batch]);
    }

    /**
     * File details for the media library drawer: uploader, dates, and the image
     * dimensions (read from the file header, no full decode).
     */
    public function show(Media $media): JsonResponse
    {
        return response()->json($this->details($media));
    }

    /** @return array<string, mixed> */
    private function details(Media $media): array
    {
        $media->load(['creator:id,name', 'editor:id,name']);
        $timezone = SettingsServiceProvider::displayTimezone();

        $dimensions = $media->width !== null && $media->height !== null
            ? ['width' => $media->width, 'height' => $media->height]
            : ($media->isImage() ? ImageOptimizer::dimensions($media->filename) : null);
        // Every referencing record with an edit link (see MediaUsage::place()).
        $places = $this->usage->places($media);

        return [
            'id' => $media->id,
            'original_name' => $media->original_name,
            'alt' => $media->alt,
            'folder' => $media->folder,
            'filename' => $media->filename,
            'mime_type' => $media->mime_type,
            'type' => $media->type,
            'size' => $media->size,
            'url' => $media->url(),
            'thumb_url' => $media->thumbUrl(),
            'srcset' => $media->srcset(),
            'focal_x' => $media->focal_x,
            'focal_y' => $media->focal_y,
            'dimensions' => $dimensions,
            'uploaded_by' => $media->creator?->name,
            'updated_by' => $media->editor?->name,
            'created_at' => $media->created_at?->toIso8601String(),
            'updated_at' => $media->updated_at?->toIso8601String(),
            'created_local' => $media->created_at?->timezone($timezone)->format('d.m.Y H:i'),
            'usages' => array_values(array_unique(array_column($places, 'label'))),
            'places' => $places,
        ];
    }

    /**
     * Replaces the file behind an existing record. The new file is processed in
     * the queue like a regular upload; the record keeps its id and display name,
     * the old files are removed once the new ones are stored. The file URL
     * changes, so external links to the old URL stop working.
     */
    public function replace(ReplaceMediaRequest $request, Media $media): JsonResponse
    {
        $batch = (string) Str::uuid();
        $this->media->queueReplacement($media, $request->file('file'), $request->user()?->id, $batch);

        return response()->json(['queued' => 1, 'batch' => $batch]);
    }

    /**
     * Crops an image synchronously and returns the updated details. The file URL
     * changes (a new file is written), like with a replacement.
     */
    public function crop(CropMediaRequest $request, Media $media): JsonResponse
    {
        $this->media->crop($media, $request->area());

        return response()->json($this->details($media->refresh()));
    }

    /**
     * Updates the editable metadata: display name, alt text, folder, focal point.
     *
     * The physical path on disk (filename) stays, so existing links and
     * thumbnails keep working. The change is written to the activity log by the
     * LogsActivity trait.
     */
    public function update(RenameMediaRequest $request, Media $media): RedirectResponse
    {
        $media->update($request->validated());

        return back()->with('success', 'Сведения о файле обновлены');
    }

    /**
     * Moves the selected files (or every matching one, see selectedIds) into
     * the target folder; an empty target removes them from any folder.
     */
    public function bulkFolder(BulkMediaFolderRequest $request): RedirectResponse
    {
        $count = $this->media->moveToFolder($this->selectedIds($request), $request->validated('target'));

        return back()->with('success', "Перемещено файлов: {$count}");
    }

    /** Creates an empty folder at the library root or inside parent. */
    public function createFolder(MediaFolderRequest $request): RedirectResponse
    {
        $name = $request->validated('name');
        $this->media->createFolder($request->validated('parent'), $name);

        return back()->with('success', "Папка «{$name}» создана");
    }

    /**
     * Renames a folder in place (name is the new last part of its path); merging
     * into an existing folder is allowed. With open=1 (renaming the folder being
     * browsed) the folder opens under its new path.
     */
    public function renameFolder(MediaFolderRequest $request): RedirectResponse
    {
        $from = $request->validated('folder');
        $count = $this->media->renameFolder($from, $request->validated('name'));
        $to = MediaFolderPath::join(MediaFolderPath::parent($from), $request->validated('name'));

        $response = $request->boolean('open')
            ? redirect()->route('admin.media.index', ['folder' => $to])
            : back();

        return $response->with('success', "Папка переименована. Файлов: {$count}");
    }

    /**
     * Deletes a folder with its subfolders; their files move up into the parent
     * folder. When the deleted folder was open, index() sends the stale address
     * to the nearest folder that still exists.
     */
    public function clearFolder(MediaFolderRequest $request): RedirectResponse
    {
        $count = $this->media->clearFolder($request->validated('folder'));

        return back()->with('success', $count > 0
            ? "Папка удалена, файлы перенесены уровнем выше: {$count}"
            : 'Папка удалена');
    }

    /**
     * Delete a single file.
     *
     * Removes the file from storage and the record from the database. A file
     * that is still referenced is kept and the places are named in the error.
     */
    public function destroy(Media $media): RedirectResponse
    {
        try {
            $this->media->delete($media);
        } catch (ValidationException $e) {
            return back()->with('error', (string) collect($e->errors())->flatten()->first());
        }

        return $this->redirectToList('admin.media.index')
            ->with('success', 'Медиа удалено');
    }

    /**
     * Bulk deletion of files.
     *
     * Deletes the picked files (ids[]) or every file matching the list filters
     * (all=1 + search/type/folder/usage, see selectedIds) along with their files in storage.
     *
     * @param  BulkDestroyMediaRequest  $request  ids (int[]) — media identifiers
     */
    public function bulkDestroy(BulkDestroyMediaRequest $request): RedirectResponse
    {
        ['deleted' => $count, 'skipped' => $skipped] = $this->media->bulkDelete($this->selectedIds($request));

        $response = $this->redirectToList('admin.media.index');
        if ($skipped !== []) {
            $names = implode(', ', array_slice($skipped, 0, 5)).(count($skipped) > 5 ? ' и др.' : '');
            $response->with('warning', 'Не удалены используемые файлы ('.count($skipped)."): {$names}");
        }

        return $response->with('success', "Удалено медиа: {$count}");
    }

    /**
     * Browse the library as JSON for the media picker (search + pagination).
     *
     * Unlike index() (a full Inertia page), this feeds media pickers on other
     * screens. Returns a paginator payload:
     * { data: [...], current_page, last_page }.
     */
    public function browse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', 'string', 'in:'.implode(',', self::TYPES)],
        ]);

        $media = Media::query()
            ->when($data['search'] ?? null, fn ($q, $term) => $q->search($term))
            ->when($data['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->orderByDesc('id')
            ->paginate(24, ['*'], 'page', $data['page'] ?? 1);

        return response()->json([
            'data' => $media->getCollection()->map(fn (Media $m) => [
                'id' => $m->id,
                'url' => $m->url(),
                'thumb_url' => $m->thumbUrl(),
                'srcset' => $m->srcset(),
                'width' => $m->width,
                'height' => $m->height,
                'focal_x' => $m->focal_x,
                'focal_y' => $m->focal_y,
                'type' => $m->type,
                'original_name' => $m->original_name,
                'alt' => $m->alt,
                'folder' => $m->folder,
                'size' => $m->size,
            ])->all(),
            'current_page' => $media->currentPage(),
            'last_page' => $media->lastPage(),
        ]);
    }

    /**
     * Upload progress of one store()/replace() request, identified by its batch id.
     *
     * items — records whose current file came from the batch (new uploads and
     * replacements); failed — upload_failed entries of the batch; duplicates —
     * files skipped because the same content is already in the library.
     */
    public function poll(Request $request): JsonResponse
    {
        $batch = $request->validate(['batch' => ['required', 'uuid']])['batch'];
        $timezone = SettingsServiceProvider::displayTimezone();

        $medias = Media::where('upload_batch', $batch)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $usages = $this->usage->for($medias);
        $items = $medias->map(fn (Media $p) => [
            'id' => $p->id,
            'url' => $p->url(),
            'thumb_url' => $p->thumbUrl(),
            'srcset' => $p->srcset(),
            'type' => $p->type,
            'mime_type' => $p->mime_type,
            'filename' => basename($p->filename),
            'original_name' => $p->original_name,
            'alt' => $p->alt,
            'folder' => $p->folder,
            'size' => $p->size,
            'focal_x' => $p->focal_x,
            'focal_y' => $p->focal_y,
            'created_at' => $p->created_at->toIso8601String(),
            'created_local' => $p->created_at->timezone($timezone)->format('d.m.Y H:i'),
            'usages' => $usages[$p->id] ?? [],
        ])->values();

        // The batch id sits in the log diff; this user's recent entries are few,
        // so they are filtered here instead of with a driver-specific JSON query.
        $logs = ActivityLog::query()
            ->whereIn('action', ['upload_failed', 'upload_duplicate'])
            ->where('user_id', $request->user()->id)
            ->where('created_at', '>=', now()->subDay())
            ->orderBy('id')
            ->limit(500)
            ->get(['id', 'action', 'subject_id', 'subject_label', 'changes'])
            ->filter(fn (ActivityLog $log) => ($log->changes['batch'][1] ?? null) === $batch);

        return response()->json([
            'items' => $items,
            'failed' => $logs->where('action', 'upload_failed')->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'name' => $log->subject_label ?: 'Файл',
                'error' => (string) ($log->changes['error'][1] ?? 'Не удалось обработать файл'),
            ])->values(),
            'duplicates' => $logs->where('action', 'upload_duplicate')->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'name' => $log->subject_label ?: 'Файл',
                'existing_id' => $log->subject_id,
            ])->values(),
        ]);
    }
}
