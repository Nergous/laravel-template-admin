<?php

namespace App\Services\System;

use App\Models\Setting;
use App\Support\Settings\SettingsSchema;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\QueryException;

/**
 * Application settings: typed reading, writing and caching.
 *
 * The structure (fields, types, defaults, admin pages) is defined in
 * SettingsSchema; values are stored as strings in the settings table (the
 * Setting model). The whole set is cached forever and flushed on write.
 *
 * Scoped (class attribute): within one request or job all() reads the cache
 * once. Settings are read several times per request (middleware, templates,
 * the login limiter), and with the database cache each read is an extra query.
 * App\Http\Middleware\ApplyRuntimeSettings copies them into the web request config.
 *
 * While the settings table does not exist (before migrations) every value is
 * the default, and callers need not catch database errors.
 */
#[Scoped]
class Settings
{
    private const CACHE_KEY = 'settings.all';

    /** @var array<string, array<string, mixed>>|null The set already read in this request */
    private ?array $resolved = null;

    public function __construct(private readonly Cache $cache) {}

    /**
     * All settings by group with cast types; keys missing from the database get
     * the defaults from SettingsSchema::FIELDS.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $stored = $this->stored();
        $out = [];

        foreach (SettingsSchema::FIELDS as $group => $keys) {
            foreach ($keys as $key => [$type, $default]) {
                $full = "{$group}.{$key}";
                $raw = array_key_exists($full, $stored) ? $stored[$full][1] : $default;
                $out[$group][$key] = $type->cast($raw);
            }
        }

        return $this->resolved = $out;
    }

    /** @return array<string, mixed> Settings of one group */
    public function group(string $group): array
    {
        return $this->all()[$group] ?? [];
    }

    /** One setting value with its type cast (null — no such key). */
    public function value(string $group, string $key): mixed
    {
        return $this->all()[$group][$key] ?? null;
    }

    /** Writes one value (only a key declared in SettingsSchema::FIELDS) and flushes the cache. */
    public function set(string $group, string $key, mixed $value): void
    {
        $row = $this->row($group, $key, $value);

        if ($row !== null) {
            Setting::query()->updateOrCreate(['key' => $row['key']], $row);
            $this->flush();
        }
    }

    /**
     * Writes settings by group in one upsert. Keys not in SettingsSchema::FIELDS
     * are dropped. Inside a transaction the caller flushes the cache again after
     * the commit: another request may have cached the old values meanwhile.
     *
     * @param  array<string, array<string, mixed>>  $groups  group → key → value
     */
    public function setMany(array $groups): void
    {
        $rows = [];

        foreach ($groups as $group => $values) {
            foreach ($values as $key => $value) {
                $row = $this->row($group, $key, $value);
                if ($row !== null) {
                    $rows[] = $row;
                }
            }
        }

        if ($rows !== []) {
            Setting::query()->upsert($rows, ['key'], ['group', 'type', 'value']);
            $this->flush();
        }
    }

    /** Flushes the cache and the set read in this request. */
    public function flush(): void
    {
        $this->cache->forget(self::CACHE_KEY);
        $this->resolved = null;
    }

    /**
     * Stored values from the cache or the database. While there is no table the
     * result is empty and nothing is cached: values are read after migrations.
     *
     * @return array<string, array{0: string, 1: ?string}> key → [type, value as string]
     */
    private function stored(): array
    {
        try {
            return $this->cache->rememberForever(
                self::CACHE_KEY,
                fn () => Setting::query()->get()
                    ->mapWithKeys(fn (Setting $setting) => [$setting->key => [$setting->type, $setting->value]])
                    ->all(),
            );
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * The table row for one setting; null when the key is not declared in SettingsSchema::FIELDS.
     *
     * @return array{key: string, group: string, type: string, value: string}|null
     */
    private function row(string $group, string $key, mixed $value): ?array
    {
        $type = SettingsSchema::type($group, $key);

        if ($type === null) {
            return null;
        }

        return [
            'key' => "{$group}.{$key}",
            'group' => $group,
            'type' => $type->value,
            'value' => $type->serialize($value),
        ];
    }
}
