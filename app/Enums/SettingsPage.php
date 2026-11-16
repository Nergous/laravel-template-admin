<?php

namespace App\Enums;

/**
 * An admin page that saves a set of settings fields. The page fields are
 * described in fields(); validation and saving go by them.
 */
enum SettingsPage: string
{
    /** The /admin/settings page: admin panel and site settings. */
    case Settings = 'settings';

    /**
     * Page fields: group => keys, null means the whole group. The order matches the page.
     *
     * @return array<string, list<string>|null>
     */
    public function fields(): array
    {
        return match ($this) {
            self::Settings => [
                'general' => null,
                'seo' => null,
                'security' => null,
                'appearance' => null,
            ],
        };
    }

    /**
     * Whether the field belongs to the page.
     *
     * @param  string  $group  Setting group
     * @param  string  $key  Key inside the group
     */
    public function contains(string $group, string $key): bool
    {
        $fields = $this->fields();

        return array_key_exists($group, $fields)
            && ($fields[$group] === null || in_array($key, $fields[$group], true));
    }

    /**
     * Picks only this page's fields from the settings, in page order.
     *
     * @param  array<string, array<string, mixed>>  $settings  Group => key => value
     * @return array<string, array<string, mixed>>
     */
    public function pick(array $settings): array
    {
        $out = [];

        foreach ($this->fields() as $group => $keys) {
            if (! isset($settings[$group]) || ! is_array($settings[$group])) {
                continue;
            }

            $out[$group] = $keys === null
                ? $settings[$group]
                : array_intersect_key($settings[$group], array_flip($keys));
        }

        return $out;
    }
}
