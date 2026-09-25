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
use App\Providers\SettingsServiceProvider;
use App\Services\ImageOptimizer;
use App\Services\MediaService;
use App\Support\MediaUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
    private const TYPES = ['image', 'video', 'audio', 'document', 'other'];

    /** Usage filter values: files referenced somewhere (see MediaUsage) or not. */
    private const USAGES = ['used', 'unused'];

    public function __construct(
        private readonly MediaService $media,
        private readonly MediaUsage $usage,
    ) {}

    /**
     * List of media with a type filter, search, sorting, and page size.
     *
     * Supported sort parameters (GET):
     * - sort (id|original_name|created_at) — the sort field
     * - direction (asc|desc)               — the sort direction
     * - type (image|video|audio|document|other), search, per_page
     * - folder — a folder name, or __none__ for files outside any folder
     * - usage (used|unused) — whether the settings reference the file
     *
     * typeCounts ignores the type filter (it labels the type switch), but
     * respects search, folder and usage.
     */
    public function index(Request $request, MediaSort $sort, MediaPerPage $perPage): Response|RedirectResponse
    {
        $type = in_array($request->query('type'), self::TYPES, true) ? $request->query('type') : null;
        $usage = in_array($request->query('usage'), self::USAGES, true) ? $request->query('usage') : null;
        $folder = $request->query('folder');
        $folder = is_string($folder) && mb_strlen($folder) <= 100 ? $folder : null;
        $referenced = $usage !== null ? $this->usage->referencedFilenames() : [];

        $base = Media::query()
            ->when($request->filled('search'), fn ($q) => $q->search((string) $request->query('search')))
            ->when($folder === '__none__', fn ($q) => $q->whereNull('folder'))
            ->when($folder !== null && $folder !== '' && $folder !== '__none__', fn ($q) => $q->where('folder', $folder))
            ->when($usage === 'used', fn ($q) => $q->whereIn('filename', $referenced))
            ->when($usage === 'unused' && $referenced !== [], fn ($q) => $q->whereNotIn('filename', $referenced));

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
            'folders' => Media::query()->whereNotNull('folder')->distinct()->orderBy('folder')->pluck('folder'),
            'folderCounts' => Media::query()
                ->whereNotNull('folder')
                ->selectRaw('folder, count(*) as aggregate')
                ->groupBy('folder')
                ->pluck('aggregate', 'folder')
                ->map(fn ($count) => (int) $count),
            'typeCounts' => $typeCounts,
            'uploadRules' => [
                'extensions' => MediaRequest::ALLOWED_EXTENSIONS,
                'maxSizeKb' => MediaRequest::MAX_SIZE_KB,
                'maxFiles' => MediaRequest::MAX_FILES,
            ],
        ]);
    }

    /**
     * Queues files for upload.
     *
     * Files are temporarily saved to storage/app/temp,
     * then a Job moves them to the media disk.
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
        $queued = $this->media->queue($request->file('media', []), $request->user()?->id, $batch);

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
            'usages' => $this->usage->for([$media])[$media->id] ?? [],
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
     * Moves the selected files into a folder; an empty folder removes them from any folder.
     */
    public function bulkFolder(BulkMediaFolderRequest $request): RedirectResponse
    {
        $count = $this->media->moveToFolder(
            $request->validated('ids'),
            $request->validated('folder'),
        );

        return back()->with('success', "Перемещено файлов: {$count}");
    }

    /** Renames a folder; merging into an existing folder is allowed. */
    public function renameFolder(MediaFolderRequest $request): RedirectResponse
    {
        $count = $this->media->renameFolder($request->validated('folder'), $request->validated('name'));

        return redirect()
            ->route('admin.media.index', ['folder' => $request->validated('name')])
            ->with('success', "Папка переименована. Файлов: {$count}");
    }

    /** Dissolves a folder; its files stay in the library outside any folder. */
    public function clearFolder(MediaFolderRequest $request): RedirectResponse
    {
        $count = $this->media->clearFolder($request->validated('folder'));

        return redirect()
            ->route('admin.media.index')
            ->with('success', "Папка удалена, файлы остались в медиатеке: {$count}");
    }

    /**
     * Delete a single file.
     *
     * Removes the file from storage and the record from the database.
     */
    public function destroy(Media $media): RedirectResponse
    {
        $this->media->delete($media);

        return $this->redirectToList('admin.media.index')
            ->with('success', 'Медиа удалено');
    }

    /**
     * Bulk deletion of files.
     *
     * Accepts an ids[] array and deletes all specified files
     * along with their files from storage.
     *
     * @param  BulkDestroyMediaRequest  $request  ids (int[]) — media identifiers
     */
    public function bulkDestroy(BulkDestroyMediaRequest $request): RedirectResponse
    {
        $count = $this->media->bulkDelete($request->ids);

        return $this->redirectToList('admin.media.index')
            ->with('success', "Удалено медиа: {$count}");
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
