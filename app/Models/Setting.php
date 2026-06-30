<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Application settings (typed key/value).
 *
 * The source of truth for the structure is the SCHEMA constant: group → key →
 * [type, default value]. It defines the defaults, the type casting, and the set
 * of validated fields (see UpdateSettingsRequest). Values are stored as strings
 * and read with type casting. The whole set is cached forever and flushed on
 * write.
 */
class Setting extends Model
{
    protected $fillable = ['group', 'key', 'value', 'type'];

    private const CACHE_KEY = 'settings.all';

    /** Key for request-scoped memoization of grouped() in the container (see grouped()). */
    private const MEMO_KEY = 'settings.grouped.memo';

    /**
     * Branding defaults (app_name, meta_title_template, canonical_domain) are
     * template placeholders. Replace them for your project here or in the
     * /admin/settings UI.
     *
     * @var array<string, array<string, array{0:string,1:mixed}>>
     */
    public const SCHEMA = [
        'general' => [
            'app_name' => ['string', 'Admin'],
            'timezone' => ['string', 'Europe/Moscow'],
            'favicon' => ['string', ''], // image URL from the media library
        ],
        'seo' => [
            'meta_title_template' => ['string', '%s — Admin'],
            'meta_description' => ['text', 'Панель управления.'],
            'canonical_domain' => ['string', ''], // e.g. https://example.com — for canonical/og
            'og_image' => ['string', ''], // image URL from the media library
            'indexable' => ['bool', true],
            'sitemap' => ['bool', true],
        ],
        'security' => [
            'session_lifetime' => ['int', 120],
            'login_throttle' => ['int', 5],
        ],
    ];

    private static function castValue(string $type, mixed $raw): mixed
    {
        return match ($type) {
            'bool' => (bool) (int) $raw,
            'int' => (int) $raw,
            default => (string) $raw,
        };
    }

    private static function serializeValue(string $type, mixed $value): string
    {
        return match ($type) {
            'bool' => $value ? '1' : '0',
            'int' => (string) (int) $value,
            default => (string) ($value ?? ''),
        };
    }

    /** @return array<string, array{0:string,1:?string}> key → [type, rawValue] */
    private static function stored(): array
    {
        return Cache::rememberForever(
            self::CACHE_KEY,
            fn () => static::query()->get()
                ->mapWithKeys(fn (Setting $s) => [$s->key => [$s->type, $s->value]])
                ->all()
        );
    }

    /**
     * The full settings map, grouped and cast to types, substituting defaults
     * from SCHEMA for keys missing in the DB.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function grouped(): array
    {
        // Request-scoped memoization. Within a single web request grouped() is
        // called multiple times (AppServiceProvider tweaks config, blade reads
        // favicon, the "login" limiter — throttle), and each call would otherwise
        // re-read stored() from the cache store — on the default `database` cache
        // that is an extra DB query on every read. We cache the result in the
        // container: it is recreated for every HTTP request, so the binding lives
        // exactly one request and does not leak between requests or between tests
        // (unlike a static property). Flushed from flushCache() on write.
        if (app()->bound(self::MEMO_KEY)) {
            return app(self::MEMO_KEY);
        }

        $stored = static::stored();
        $out = [];

        foreach (self::SCHEMA as $group => $keys) {
            foreach ($keys as $key => [$type, $default]) {
                $full = "{$group}.{$key}";
                $raw = array_key_exists($full, $stored) ? $stored[$full][1] : $default;
                $out[$group][$key] = static::castValue($type, $raw);
            }
        }

        app()->instance(self::MEMO_KEY, $out);

        return $out;
    }

    /** Returns a single setting value, cast to its type (or null). */
    public static function value(string $group, string $key): mixed
    {
        return static::grouped()[$group][$key] ?? null;
    }

    /**
     * Builds a table row for a single setting, or null if the key is not
     * declared in SCHEMA. Shared builder for set()/setMany().
     *
     * @return array{key:string,group:string,type:string,value:string}|null
     */
    private static function row(string $group, string $key, mixed $value): ?array
    {
        if (! isset(self::SCHEMA[$group][$key])) {
            return null;
        }

        [$type] = self::SCHEMA[$group][$key];

        return [
            'key' => "{$group}.{$key}",
            'group' => $group,
            'type' => $type,
            'value' => static::serializeValue($type, $value),
        ];
    }

    /** Writes a single value (only if the key is declared in SCHEMA). */
    public static function set(string $group, string $key, mixed $value): void
    {
        if ($row = static::row($group, $key, $value)) {
            static::updateOrCreate(['key' => $row['key']], $row);
        }
    }

    /**
     * Bulk-writes settings by group in a single upsert query (instead of N
     * updateOrCreate calls). Keys missing from SCHEMA are discarded.
     *
     * @param  array<string, array<string, mixed>>  $groups  group → key → value
     */
    public static function setMany(array $groups): void
    {
        $rows = [];

        foreach ($groups as $group => $values) {
            foreach ($values as $key => $value) {
                if ($row = static::row($group, $key, $value)) {
                    $rows[] = $row;
                }
            }
        }

        if ($rows) {
            static::upsert($rows, ['key'], ['group', 'type', 'value']);
        }
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
        app()->forgetInstance(self::MEMO_KEY);
    }
}
