<?php

namespace App\Http\Controllers\Admin\System;

use App\Enums\AccentPreset;
use App\Enums\SettingsPage;
use App\Http\Controllers\Controller;
use App\Http\Requests\System\UpdateSettingsRequest;
use App\Services\System\SettingsService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Application settings: reading and saving the SettingsPage::Settings fields.
 */
class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Returns the settings (grouped). Favicon and OG images are picked via the
     * shared MediaPicker (GET /admin/media/browse).
     */
    public function index(): Response
    {
        return Inertia::render('Settings/Index', [
            'settings' => $this->settings->values(SettingsPage::Settings),
            'accents' => AccentPreset::options(),
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $this->settings->save($request->validated('settings'), $request->page(), $request->user());

        return back()->with('success', 'Настройки сохранены');
    }
}
