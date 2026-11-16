<?php

namespace App\Support\Settings;

use App\Enums\SettingType;

/**
 * Application settings fields (App\Models\Setting): group → key → [type, default value].
 *
 * Values are stored as strings in the settings table and cast on read
 * (SettingType::cast()). Admin pages and validation rules live in SettingsPage and SettingsRules.
 */
final class SettingsSchema
{
    /**
     * Settings fields with their defaults. Branding defaults (app_name,
     * meta_title_template, meta_description) are template placeholders: replace
     * them for your project here or on /admin/settings.
     *
     * @var array<string, array<string, array{0: SettingType, 1: mixed}>>
     */
    public const FIELDS = [
        'general' => [
            'app_name' => [SettingType::String, 'Admin Panel'],
            'timezone' => [SettingType::String, 'Europe/Moscow'],
            'favicon' => [SettingType::String, ''], // image URL from the media library
        ],
        'seo' => [
            'meta_title_template' => [SettingType::String, '%s — Admin Panel'],
            'meta_description' => [SettingType::Text, 'Панель управления.'],
            'canonical_domain' => [SettingType::String, ''], // e.g. https://example.com — for canonical/og
            'og_image' => [SettingType::String, ''], // image URL from the media library
            'indexable' => [SettingType::Bool, true],
            'sitemap' => [SettingType::Bool, true],
        ],
        'security' => [
            'session_lifetime' => [SettingType::Int, 120],
            'login_throttle' => [SettingType::Int, 5],
        ],
        // Admin appearance: accent color preset (App\Services\System\AccentColor).
        'appearance' => [
            'accent' => [SettingType::String, 'blue'],
        ],
    ];

    /**
     * Type of the "group.key" field; null when the field is not declared in FIELDS.
     */
    public static function type(string $group, string $key): ?SettingType
    {
        return self::FIELDS[$group][$key][0] ?? null;
    }
}
