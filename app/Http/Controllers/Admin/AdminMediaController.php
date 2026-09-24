<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Pagination\MediaPerPage;
use App\Http\Requests\BulkDestroyMediaRequest;
use App\Http\Requests\MediaRequest;
use App\Http\Requests\RenameMediaRequest;
use App\Http\Requests\ReplaceMediaRequest;
use App\Http\Sorts\MediaSort;
use App\Models\Media;
use App\Providers\SettingsServiceProvider;
use App\Services\MediaService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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

    public function __construct(private readonly MediaService $media) {}

    /**
     * List of media with a type filter, search, sorting, and page size.
     *
     * Supported sort parameters (GET):
     * - sort (id|original_name|created_at) — the sort field
     * - direction (asc|desc)               — the sort direction
     * - type (image|video|audio|document|other), search, per_page
     */
    public function index(Request $request, MediaSort $sort, MediaPerPage $perPage): Response
    {
        $type = in_array($request->query('type'), self::TYPES, true) ? $request->query('type') : null;

        $media = Media::query()
            ->when($request->filled('search'), fn ($q) => $q->search((string) $request->query('search')))
            ->when($type, fn ($q, string $t) => $q->where('type', $t))
            ->orderBy($sort->getSort(), $sort->getDirection())
            ->orderBy('id', $sort->getDirection())
            ->paginate($perPage->get())
            ->withQueryString();

        return Inertia::render('Media/Index', [
            'media' => $media,
            ...$sort->toArray(), // currentSort + currentDirection from the validated Sort
            ...$perPage->toArray(),
            'filters' => [
                'search' => (string) $request->query('search', ''),
                'type' => $type ?? '',
            ],
        ]);
    }

    /**
     * Queues files for upload.
     *
     * Files are temporarily saved to storage/app/temp,
     * then a Job moves them to storage/public/media.
     *
     * @return JsonResponse The number of files queued:
     *                      { "queued": 3 }
     */
    public function store(MediaRequest $request): JsonResponse
    {
        // Rows created by this batch get ids above the current maximum, and the
        // poll filters by the uploader — so other admins' uploads are not mixed in.
        $afterId = (int) Media::max('id');

        // Q4: take the files once with a default of [] — don't rely on the key
        // being present (count(null) would be a TypeError if the form rules change).
        $queued = $this->media->queue($request->file('media', []), $request->user()?->id);

        return response()->json(['queued' => $queued, 'after_id' => $afterId]);
    }

    /**
     * File details for the media library drawer: uploader, dates, and the image
     * dimensions (read from the file header, no full decode).
     */
    public function show(Media $media): JsonResponse
    {
        $media->load(['creator:id,name', 'editor:id,name']);
        $timezone = SettingsServiceProvider::displayTimezone();

        $dimensions = null;
        $disk = Storage::disk('public');
        if ($media->isImage() && $disk->exists($media->filename)) {
            $info = @getimagesize($disk->path($media->filename));
            $dimensions = $info ? ['width' => $info[0], 'height' => $info[1]] : null;
        }

        return response()->json([
            'id' => $media->id,
            'original_name' => $media->original_name,
            'filename' => $media->filename,
            'mime_type' => $media->mime_type,
            'type' => $media->type,
            'size' => $media->size,
            'url' => $media->url(),
            'thumb_url' => $media->thumbUrl(),
            'dimensions' => $dimensions,
            'uploaded_by' => $media->creator?->name,
            'updated_by' => $media->editor?->name,
            'created_at' => $media->created_at?->toIso8601String(),
            'updated_at' => $media->updated_at?->toIso8601String(),
            'created_local' => $media->created_at?->timezone($timezone)->format('d.m.Y H:i'),
        ]);
    }

    /**
     * Replaces the file behind an existing record. The new file is processed in
     * the queue like a regular upload; the record keeps its id and display name,
     * the old files are removed once the new ones are stored. The file URL
     * changes, so external links to the old URL stop working.
     */
    public function replace(ReplaceMediaRequest $request, Media $media): JsonResponse
    {
        $this->media->queueReplacement($media, $request->file('file'), $request->user()?->id);

        return response()->json(['queued' => 1, 'filename' => $media->filename]);
    }

    /**
     * Rename a file (the display name).
     *
     * Only original_name changes — the physical path on disk (filename) stays,
     * so existing attachment links and thumbnails keep working. The change is
     * written to the activity log by the LogsActivity trait.
     */
    public function update(RenameMediaRequest $request, Media $media): RedirectResponse
    {
        $media->update(['original_name' => trim($request->input('original_name'))]);

        return back()->with('success', 'Файл переименован');
    }

    /**
     * Delete a single file.
     *
     * Removes the file from storage and the record from the database.
     */
    public function destroy(Media $media): RedirectResponse
    {
        $this->media->delete($media);

        return redirect()
            ->route('admin.media.index')
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

        return redirect()
            ->route('admin.media.index')
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
                'type' => $m->type,
                'original_name' => $m->original_name,
                'size' => $m->size,
            ])->all(),
            'current_page' => $media->currentPage(),
            'last_page' => $media->lastPage(),
        ]);
    }

    /**
     * Polling for the frontend: returns a JSON array of media newer than after_id
     * (by descending id, no more than limit), uploaded by the current user. Used
     * to track the progress of asynchronous uploads: after_id comes from store().
     */
    public function poll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $afterId = (int) ($data['after_id'] ?? 0);
        $limit = (int) ($data['limit'] ?? 50);
        $timezone = SettingsServiceProvider::displayTimezone();

        /** @var Collection<int, Media> $medias */
        $medias = Media::where('id', '>', $afterId)
            ->where('created_by', $request->user()->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return response()->json(
            $medias->map(fn (Media $p) => [
                'id' => $p->id,
                'url' => $p->url(),
                'thumb_url' => $p->thumbUrl(),
                'type' => $p->type,
                'filename' => basename($p->filename),
                'original_name' => $p->original_name,
                'size' => $p->size,
                'created_at' => $p->created_at->toIso8601String(),
                'created_local' => $p->created_at->timezone($timezone)->format('d.m.Y H:i'),
            ])
        );
    }
}
