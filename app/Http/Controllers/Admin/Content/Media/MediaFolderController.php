<?php

namespace App\Http\Controllers\Admin\Content\Media;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\Media\DeleteMediaFolderRequest;
use App\Http\Requests\Content\Media\RenameMediaFolderRequest;
use App\Http\Requests\Content\Media\StoreMediaFolderRequest;
use App\Services\Media\MediaFolderService;
use Illuminate\Http\RedirectResponse;

/** Media library folders: create, rename, delete (MediaFolderService). */
class MediaFolderController extends Controller
{
    public function __construct(private readonly MediaFolderService $folders) {}

    /** Creates an empty folder at the library root or inside a parent folder. */
    public function store(StoreMediaFolderRequest $request): RedirectResponse
    {
        $name = $request->validated('name');
        $this->folders->create($request->validated('parent'), $name, $request->user());

        return back()->with('success', "Папка «{$name}» создана");
    }

    /** Renames a folder; the browsed folder reopens at its new path. */
    public function rename(RenameMediaFolderRequest $request): RedirectResponse
    {
        $result = $this->folders->rename($request->validated('folder'), $request->validated('name'), $request->user());

        $response = $request->opensRenamed()
            ? redirect()->route('admin.media.index', ['folder' => $result['path']])
            : back();

        return $response->with('success', "Папка переименована. Файлов: {$result['moved']}");
    }

    /**
     * Deletes a folder with its subfolders; their files move to the parent
     * folder. If the deleted folder was open, the list redirects the stale
     * address to the nearest existing folder.
     */
    public function clear(DeleteMediaFolderRequest $request): RedirectResponse
    {
        $count = $this->folders->delete($request->validated('folder'), $request->user());

        return back()->with('success', $count > 0
            ? "Папка удалена, файлы перенесены уровнем выше: {$count}"
            : 'Папка удалена');
    }
}
