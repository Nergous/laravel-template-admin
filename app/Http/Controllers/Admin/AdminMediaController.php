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
 * Контроллер для управления медиа в административной панели.
 *
 * Занимается HTTP: валидирует вход, собирает пропсы Inertia и редиректит.
 * Оркестрация (постановка в очередь, транзакционное удаление + очистка файлов)
 * живёт в App\Services\MediaService. Read-методы (index/poll) остаются здесь.
 */
class AdminMediaController extends Controller
{
    public function __construct(private readonly MediaService $media) {}

    /**
     * Список медиа с сортировкой и поиском.
     *
     * Поддерживаемые параметры сортировки (GET):
     * - sort (id|original_name|created_at) — поле сортировки
     * - direction (asc|desc)               — направление сортировки
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
            ...$sort->toArray(), // currentSort + currentDirection из валидированного Sort
        ]);
    }

    /**
     * Постановка файлов в очередь на загрузку.
     *
     * Файлы временно сохраняются в storage/app/temp,
     * затем Job перемещает их в storage/public/media.
     *
     * @return JsonResponse Количество файлов поставленных в очередь:
     *                      { "queued": 3 }
     */
    public function store(MediaRequest $request): JsonResponse
    {
        // Q4: берём файлы один раз с дефолтом [] — не полагаемся на присутствие
        // ключа (count(null) был бы TypeError, если правила формы изменят).
        $queued = $this->media->queue($request->file('media', []), $request->user()?->id);

        return response()->json(['queued' => $queued]);
    }

    /**
     * Удаление одного файла.
     *
     * Удаляет файл из storage и запись из базы данных.
     */
    public function destroy(Media $media): RedirectResponse
    {
        $this->media->delete($media);

        return redirect()
            ->route('admin.media.index')
            ->with('success', 'Медиа удалено');
    }

    /**
     * Массовое удаление файлов.
     *
     * Принимает массив ids[] и удаляет все указанные файлы
     * вместе с файлами из storage.
     *
     * @param  BulkDestroyMediaRequest  $request  ids (int[]) — идентификаторы медиа
     */
    public function bulkDestroy(BulkDestroyMediaRequest $request): RedirectResponse
    {
        $count = $this->media->bulkDelete($request->ids);

        return redirect()
            ->route('admin.media.index')
            ->with('success', "Удалено медиа: {$count}");
    }

    /**
     * Поллинг для фронтенда: возвращает JSON-массив медиа новее after_id
     * (по убыванию id, не более limit). Служит для отслеживания прогресса
     * асинхронной загрузки.
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
