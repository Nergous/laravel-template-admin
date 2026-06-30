<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * Просмотр и очистка журнала действий (аудита) в админ-панели.
 *
 * Чтение (index/recent) гейтится правом activity-log.view; очистка журнала до
 * выбранной даты (clear) — отдельным правом activity-log.delete. Записи в журнал
 * пишутся отдельно (см. трейт LogsActivity), здесь они читаются и массово чистятся.
 */
class AdminActivityLogController extends Controller
{
    /**
     * Отдаёт постраничный (по 30) журнал действий на страницу ActivityLog/Index.
     *
     * Поддерживает необязательные фильтры action и subject_type; записи
     * сортируются по дате создания (новые сверху).
     *
     * @param  Request  $request  Учитываются параметры action и subject_type.
     */
    public function index(Request $request): \Inertia\Response
    {
        $query = ActivityLog::with('user')->latest('created_at');

        if ($request->filled('action')) {
            $query->where('action', $request->action);
        }

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->subject_type);
        }

        $logs = $query->paginate(30)
            ->withQueryString()
            ->through(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actionLabel' => $log->actionLabel(),
                // Живое имя автора → снимок actor_label (переживает force-delete) → «Система».
                'actor' => $log->user?->name ?? $log->actor_label ?? 'Система',
                'subject' => $log->subject_label ?: $log->subjectTypeLabel(),
                'subjectType' => $log->subjectTypeLabel(),
                'changesCount' => is_array($log->changes) ? count($log->changes) : 0,
                'changes' => $log->changes,
                'createdAt' => optional($log->created_at)->toIso8601String(),
            ]);

        return Inertia::render('ActivityLog/Index', [
            'logs' => $logs,
            'filters' => $request->only('action', 'subject_type'),
        ]);
    }

    /**
     * Очищает журнал: удаляет все записи раньше выбранной даты (начало дня).
     *
     * Дата `before` обязательна — это «очистить события до …». Удаление —
     * одним массовым DELETE без гидрации/событий (у аудит-лога событий на
     * удаление нет, чистка должна быть дешёвой; ср. ActivityLog::prunable()).
     *
     * @param  Request  $request  before (date) — дата-отсечка.
     */
    public function clear(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'before' => ['required', 'date'],
        ], [
            'before.required' => 'Укажите дату, до которой очистить журнал',
            'before.date' => 'Некорректная дата',
        ]);

        $before = Carbon::parse($validated['before'])->startOfDay();
        $deleted = ActivityLog::where('created_at', '<', $before)->delete();

        return redirect()
            ->back()
            ->with('success', "Журнал очищен. Удалено записей: {$deleted}");
    }

    /**
     * JSON-фид для «колокольчика» в сайдбаре: счётчик и последние 10 действий
     * за 24 часа. Закрыт гейтом activity-log.view (см. routes/web.php) — фид
     * отдаёт ту же ленту аудита, что и страница index(), поэтому требует то же
     * право, а не только аутентификацию.
     */
    public function recent(): \Illuminate\Http\JsonResponse
    {
        $count = ActivityLog::recent()->count();

        $items = ActivityLog::with('user')
            ->recent()
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'user' => $log->user?->name ?? $log->actor_label ?? '—',
                'action' => $log->actionLabel(),
                'subject' => $log->subject_label ?? $log->subjectTypeLabel(),
                'time' => $log->created_at->diffForHumans(),
                'iso_time' => $log->created_at->toIso8601String(),
                'url' => route('admin.activity-log.index'),
            ]);

        return response()->json([
            'count' => $count,
            'items' => $items,
        ]);
    }
}
