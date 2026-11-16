<?php

namespace App\Http\Requests\System;

use App\Enums\SettingsPage;
use App\Http\Requests\SettingsFormRequest;

/** Saving /admin/settings: admin panel and site settings. */
class UpdateSettingsRequest extends SettingsFormRequest
{
    public function page(): SettingsPage
    {
        return SettingsPage::Settings;
    }

    protected function permission(): string
    {
        return 'settings.edit';
    }
}
