<?php

namespace App\Services\System;

use App\Enums\AccentPreset;

/**
 * Admin accent color (settings → appearance.accent), shared by all users. Each
 * preset (AccentPreset) is a set of light and dark theme design tokens in
 * resources/js/admin/accents.css, chosen so that white text on the accent and
 * accent text on its light background keep WCAG AA contrast. The server puts
 * the key into <html data-accent> (admin.blade.php), so the color is right from
 * the first paint.
 */
class AccentColor
{
    public const DEFAULT = AccentPreset::Blue;

    public function __construct(private readonly Settings $settings) {}

    /**
     * The saved preset or the default one. The admin template also renders the
     * 500 page; Settings itself survives an unavailable database.
     */
    public function current(): AccentPreset
    {
        return AccentPreset::tryFrom((string) $this->settings->value('appearance', 'accent')) ?? self::DEFAULT;
    }
}
