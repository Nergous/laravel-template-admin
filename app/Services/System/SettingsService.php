<?php

namespace App\Services\System;

use App\Enums\SettingsPage;
use App\Events\ActivityOccurred;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;

/**
 * Reading and saving the fields of a settings page. Saving is one upsert, a
 * cache flush after the commit and a log entry with the changed keys. Settings
 * control the login limit, the session lifetime, SEO and what the public site
 * shows, so every change must be traceable.
 */
class SettingsService
{
    public function __construct(
        private readonly Settings $settings,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * Current values of the fields the page edits, by group.
     *
     * @param  SettingsPage  $page  The page whose fields to return
     * @return array<string, array<string, mixed>>
     */
    public function values(SettingsPage $page): array
    {
        return $page->pick($this->settings->all());
    }

    /**
     * Saves the page fields in one upsert and logs the changes.
     *
     * @param  array<string, array<string, mixed>>  $input  Validated settings by group
     * @param  SettingsPage  $page  The page whose fields are saved (other keys are dropped)
     * @param  User  $actor  Who saves the settings (for the log)
     */
    public function save(array $input, SettingsPage $page, User $actor): void
    {
        $before = $this->settings->all();

        $this->db->transaction(fn () => $this->settings->setMany($page->pick($input)));

        // The cache is not transactional — flush it again after a successful commit.
        $this->settings->flush();

        $changes = $this->diff($before, $this->settings->all());
        if ($changes !== []) {
            ActivityOccurred::by($actor, null, 'settings_updated', $changes);
        }
    }

    /**
     * Values that changed between two settings snapshots, in the log diff format.
     *
     * @param  array<string, array<string, mixed>>  $before  Group => key => value before saving
     * @param  array<string, array<string, mixed>>  $after  Group => key => value after saving
     * @return array<string, array{0: mixed, 1: mixed}> "group.key" => [old, new]
     */
    private function diff(array $before, array $after): array
    {
        $changes = [];

        foreach ($after as $group => $values) {
            foreach ($values as $key => $value) {
                $old = $before[$group][$key] ?? null;

                if ($old !== $value) {
                    $changes["{$group}.{$key}"] = [$old, $value];
                }
            }
        }

        return $changes;
    }
}
