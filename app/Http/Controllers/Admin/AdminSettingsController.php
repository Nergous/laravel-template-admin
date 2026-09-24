<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSettingsRequest;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Application settings: reading and saving values by group (key-value).
 */
class AdminSettingsController extends Controller
{
    /**
     * Returns the settings (grouped). Images for the favicon and the OG image
     * are picked through the shared MediaPicker (GET /admin/media/browse).
     */
    public function index(): Response
    {
        return Inertia::render('Settings/Index', [
            'settings' => Setting::grouped(),
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
