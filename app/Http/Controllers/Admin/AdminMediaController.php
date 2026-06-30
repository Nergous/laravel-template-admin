<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BulkDestroyMediaRequest;
use App\Http\Requests\MediaRequest;
use App\Http\Sorts\MediaSort;
use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Controller for managing media in the admin panel.
 *
 * Handles HTTP: validates input, assembles Inertia props, and redirects.
 * Orchestration (queueing, transactional deletion + file cleanup)
 * lives in App\Services\MediaService. Read methods (index/poll) stay here.
 */
class AdminMediaController extends Controller
{
    public function __construct(private readonly MediaService $media) {}

    /**
     * List of media with sorting and search.
     *
     * Supported sort parameters (GET):
     * - sort (id|original_name|created_at) — the sort field
     * - direction (asc|desc)               — the sort direction
     */
    public function index(Request $request, MediaSort $sort): \Inertia\Response
    {
        $query = Media::query();

        if ($request->filled('search')) {
            $query->search($request->search);
        }

        $media = $query
            ->orderBy($sort->getSort(), $sort->getDirection())
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Media/Index', [
            'media' => $media,
            ...$sort->toArray(), // currentSort + currentDirection from the validated Sort
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
        // Q4: take the files once with a default of [] — don't rely on the key
        // being present (count(null) would be a TypeError if the form rules change).
        $queued = $this->media->queue($request->file('media', []), $request->user()?->id);

        return response()->json(['queued' => $queued]);
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
     * Unlike index() (a full Inertia page), this feeds a modal opened from other
     * screens — e.g. attaching media to a bot message. Returns a paginator payload:
     * { data: [...], current_page, last_page }.
     */
    public function browse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $media = Media::query()
            ->when($data['search'] ?? null, fn ($q, $term) => $q->search($term))
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
     * (by descending id, no more than limit). Used to track the progress of
     * asynchronous uploads.
     */
    public function poll(Request $request): JsonResponse
    {
        $data = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $afterId = (int) ($data['after_id'] ?? 0);
        $limit = (int) ($data['limit'] ?? 50);

        /** @var \Illuminate\Database\Eloquent\Collection<int, Media> $medias */
        $medias = Media::when($afterId > 0, fn ($q) => $q->where('id', '>', $afterId))
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
                'created_at' => $p->created_at->format('d.m.Y H:i'),
            ])
        );
    }
}
