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
 * Application settings: reading and saving values by group (key-value).
 */
class AdminSettingsController extends Controller
{
    /**
     * Returns the settings (grouped) and a list of media library images
     * for choosing the OG image.
     */
    public function index(): \Inertia\Response
    {
        return Inertia::render('Settings/Index', [
            'settings' => Setting::grouped(),
            // Media library images for choosing the OG image (id, url, thumb_url).
            'images' => Media::where('type', 'image')
                ->latest()
                ->limit(100)
                ->get(['id', 'filename', 'original_name', 'type']),
        ]);
    }

    /** Saves the settings by group (Setting::setMany) and flushes the cache. */
    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        /** @var array<string, array<string, mixed>> $settings */
        $settings = $request->validated()['settings'];

        DB::transaction(fn () => Setting::setMany($settings));

        // The cache is not transactional — flush it only after a successful commit.
        Setting::flushCache();

        return back()->with('success', 'Настройки сохранены');
    }
}
