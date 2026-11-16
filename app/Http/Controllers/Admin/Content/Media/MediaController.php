<?php

namespace App\Http\Controllers\Admin\Content\Media;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\Media\BulkDestroyMediaRequest;
use App\Http\Requests\Content\Media\BulkMediaFolderRequest;
use App\Http\Requests\Content\Media\CropMediaRequest;
use App\Http\Requests\Content\Media\MediaFilterRequest;
use App\Http\Requests\Content\Media\UpdateMediaRequest;
use App\Http\Resources\Media\MediaDetailsResource;
use App\Http\Resources\Media\MediaFolderResource;
use App\Http\Resources\Media\MediaResource;
use App\Models\Media;
use App\Services\Media\MediaCropper;
use App\Services\Media\MediaLibrary;
use App\Services\Media\MediaPlaces;
use App\Services\Media\MediaService;
use App\Services\Media\MediaUsage;
use App\Support\Media\UploadRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Media library files: the list, the details drawer, editing and deletion.
 * Folders, uploads and the picker have their own controllers.
 * Reads go through MediaLibrary, writes through MediaService, cropping
 * through MediaCropper.
 */
class MediaController extends Controller
{
    public function __construct(
        private readonly MediaLibrary $library,
        private readonly MediaService $media,
        private readonly MediaCropper $cropper,
        private readonly MediaUsage $usage,
        private readonly MediaPlaces $places,
    ) {}

    /**
     * The library works like a file manager: a level shows its own files and
     * subfolders (the folders prop), ?folder= opens a folder by path, without it —
     * the root. Filters, sorting and page size live in MediaFilterRequest.
     *
     * typeCounts ignores the type filter (it labels the type switch) but respects
     * search, the open level and usage.
     */
    public function index(MediaFilterRequest $request): Response|RedirectResponse
    {
        $filters = $request->filters();

        // A stale link to a renamed or deleted folder opens the nearest existing
        // folder above it, or the root.
        if ($filters->folder !== null && ($nearest = $this->library->nearestExistingFolder($filters->folder)) !== $filters->folder) {
            return redirect()->route('admin.media.index', array_filter(['folder' => $nearest]));
        }

        $media = $this->library
            ->paginate($filters, $request->sortColumn(), $request->sortDirection(), $request->perPage())
            ->withQueryString();

        if ($redirect = $this->redirectPastLastPage($media, $request)) {
            return $redirect;
        }

        $usages = $this->usage->for($media->getCollection());
        $media->through(fn (Media $item) => (new MediaResource($item, $usages[$item->id] ?? []))->resolve($request));

        return Inertia::render('Media/Index', [
            'media' => $media,
            ...$request->sortProps(),
            ...$request->perPageProps(),
            'filters' => $filters->toArray(),
            'folders' => array_map(
                fn (array $folder) => (new MediaFolderResource($folder))->resolve($request),
                $this->library->folders(),
            ),
            'typeCounts' => $this->library->typeCounts($filters),
            'uploadRules' => UploadRules::toArray(),
        ]);
    }

    /**
     * File details for the library drawer: who uploaded it, dates and image
     * dimensions.
     */
    public function show(Media $media): JsonResponse
    {
        return response()->json($this->details($media));
    }

    /**
     * Crops the image synchronously and returns the updated details. The file
     * URL changes (a new file is written), as on replacement.
     */
    public function crop(CropMediaRequest $request, Media $media): JsonResponse
    {
        $this->cropper->crop($media, $request->area(), $request->user());

        return response()->json($this->details($media->refresh()));
    }

    /** Updates the display name, alt text, folder and focal point (see MediaService::update). */
    public function update(UpdateMediaRequest $request, Media $media): RedirectResponse
    {
        $this->media->update($media, $request->validated(), $request->user());

        return back()->with('success', 'Сведения о файле обновлены');
    }

    /**
     * Moves the selected files (or all matching ones) to the target folder;
     * an empty target removes them from any folder.
     */
    public function bulkFolder(BulkMediaFolderRequest $request): RedirectResponse
    {
        $count = $this->media->moveToFolder($request->selection(), $request->validated('target'), $request->user());

        return back()->with('success', "Перемещено файлов: {$count}");
    }

    /**
     * Deletes one file from storage and the database. A file that is still
     * referenced is kept: MediaInUseException names the places using it.
     */
    public function destroy(Media $media): RedirectResponse
    {
        $this->media->delete($media);

        return $this->redirectToList('admin.media.index')
            ->with('success', 'Медиа удалено');
    }

    /**
     * Bulk deletes the selected files, or all matching the list filters, together
     * with their stored files. Files in use are kept and listed in a
     * warning.
     */
    public function bulkDestroy(BulkDestroyMediaRequest $request): RedirectResponse
    {
        $result = $this->media->bulkDelete($request->selection());

        $response = $this->redirectToList('admin.media.index');
        if ($result['warning'] !== null) {
            $response->with('warning', $result['warning']);
        }

        return $response->with('success', "Удалено медиа: {$result['deleted']}");
    }

    /** @return array<string, mixed> */
    private function details(Media $media): array
    {
        $media->load(['creator:id,name', 'editor:id,name']);

        // Every record referencing the file, with a link to edit it.
        return (new MediaDetailsResource($media, $this->places->for($media), $this->media->dimensions($media)))->resolve();
    }
}
