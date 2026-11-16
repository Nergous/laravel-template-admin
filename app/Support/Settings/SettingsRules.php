<?php

namespace App\Support\Settings;

use App\Enums\AccentPreset;
use App\Enums\SettingsPage;
use App\Enums\SettingType;
use App\Rules\SafeUrl;
use Illuminate\Validation\Rule;

/**
 * Validation rules of settings fields. A page's rule set is built from the
 * field description in SettingsSchema: some fields have their own rules, the
 * rest get the common rules of their type (SettingType::rules()).
 */
final class SettingsRules
{
    /**
     * Fields that take a link: empty, a root-relative path (/storage/...) or an
     * absolute http(s) URL.
     */
    private const URL_FIELDS = [
        'general.favicon',
        'seo.og_image',
    ];

    /**
     * Rules of the page fields: "group.key" => rules.
     *
     * @return array<string, list<mixed>>
     */
    public static function for(SettingsPage $page): array
    {
        $rules = [];

        foreach (SettingsSchema::FIELDS as $group => $keys) {
            foreach ($keys as $key => [$type]) {
                if ($page->contains($group, $key)) {
                    $rules["{$group}.{$key}"] = self::fieldRules("{$group}.{$key}", $type);
                }
            }
        }

        return $rules;
    }

    /**
     * Rules of one field: specific ones for some fields, otherwise by type.
     *
     * @return list<mixed>
     */
    private static function fieldRules(string $field, SettingType $type): array
    {
        if (in_array($field, self::URL_FIELDS, true)) {
            return ['nullable', 'string', 'max:255', new SafeUrl];
        }

        return match ($field) {
            // A session shorter than a minute would sign everyone out at once; the upper bound is 30 days.
            'security.session_lifetime' => ['required', 'integer', 'min:1', 'max:43200'],
            'security.login_throttle' => ['required', 'integer', 'min:1', 'max:1000'],
            'seo.canonical_domain' => ['nullable', 'string', 'max:255', 'regex:~^https?://[^/\s?#]+$~i'],
            'general.timezone' => ['required', 'string', 'timezone:all'],
            // Only a known preset; a form without this group keeps the saved color.
            'appearance.accent' => ['sometimes', 'required', 'string', Rule::enum(AccentPreset::class)],
            default => $type->rules(),
        };
    }
}
