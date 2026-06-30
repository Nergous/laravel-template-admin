<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSettingsRequest;
use App\Models\Media;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Настройки приложения: чтение и сохранение значений по группам (key-value).
 */
class AdminSettingsController extends Controller
{
    /**
     * Отдаёт настройки (сгруппированно) и список картинок медиатеки
     * для выбора OG-изображения.
     */
    public function index(): \Inertia\Response
    {
        return Inertia::render('Settings/Index', [
            'settings' => Setting::grouped(),
            // Изображения медиатеки для выбора OG-картинки (id, url, thumb_url).
            'images' => Media::where('type', 'image')
                ->latest()
                ->limit(100)
                ->get(['id', 'filename', 'original_name', 'type']),
        ]);
    }

    /** Сохраняет настройки по группам (Setting::setMany) и сбрасывает кэш. */
    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        /** @var array<string, array<string, mixed>> $settings */
        $settings = $request->validated()['settings'];

        DB::transaction(fn () => Setting::setMany($settings));

        // Кэш не транзакционен — сбрасываем только после успешного коммита.
        Setting::flushCache();

        return back()->with('success', 'Настройки сохранены');
    }
}
