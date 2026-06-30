<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Настройки приложения (типизированный key/value).
 *
 * Источник истины по структуре — константа SCHEMA: группа → ключ →
 * [тип, значение по умолчанию]. Она задаёт и дефолты, и приведение типов,
 * и набор валидируемых полей (см. UpdateSettingsRequest). Значения хранятся
 * строками; читаются с приведением к типу. Весь набор кэшируется навсегда
 * и сбрасывается при записи.
 */
class Setting extends Model
{
    protected $fillable = ['group', 'key', 'value', 'type'];

    private const CACHE_KEY = 'settings.all';

    /** Ключ request-scoped мемоизации grouped() в контейнере (см. grouped()). */
    private const MEMO_KEY = 'settings.grouped.memo';

    /**
     * Брендинг-дефолты (app_name, meta_title_template, canonical_domain) —
     * плейсхолдеры шаблона. Замените под свой проект здесь или в UI /admin/settings.
     *
     * @var array<string, array<string, array{0:string,1:mixed}>>
     */
    public const SCHEMA = [
        'general' => [
            'app_name' => ['string', 'Admin'],
            'timezone' => ['string', 'Europe/Moscow'],
            'favicon' => ['string', ''], // URL изображения из медиатеки
        ],
        'seo' => [
            'meta_title_template' => ['string', '%s — Admin'],
            'meta_description' => ['text', 'Панель управления.'],
            'canonical_domain' => ['string', ''], // напр. https://example.com — для canonical/og
            'og_image' => ['string', ''], // URL изображения из медиатеки
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
     * Полная карта настроек, сгруппированная и приведённая к типам,
     * с подстановкой дефолтов из SCHEMA для отсутствующих в БД ключей.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function grouped(): array
    {
        // Мемоизация в рамках запроса. За один web-запрос grouped() дёргается
        // неоднократно (AppServiceProvider правит config, blade читает favicon,
        // лимитер «login» — throttle), а каждый вызов иначе перечитывает
        // stored() из кэш-store — на дефолтном `database`-кэше это лишний запрос
        // к БД на каждое чтение. Кэшируем результат в контейнере: он
        // пересоздаётся на каждый HTTP-запрос, поэтому привязка живёт ровно один
        // запрос и не протекает ни между запросами, ни между тестами (в отличие
        // от статического свойства). Сбрасывается из flushCache() при записи.
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

    /** Возвращает одно значение настройки с приведением к типу (или null). */
    public static function value(string $group, string $key): mixed
    {
        return static::grouped()[$group][$key] ?? null;
    }

    /**
     * Строит строку таблицы для одной настройки или null, если ключ
     * не объявлен в SCHEMA. Общий билдер для set()/setMany().
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

    /** Записывает одно значение (только если ключ объявлен в SCHEMA). */
    public static function set(string $group, string $key, mixed $value): void
    {
        if ($row = static::row($group, $key, $value)) {
            static::updateOrCreate(['key' => $row['key']], $row);
        }
    }

    /**
     * Массовая запись настроек по группам одним upsert-запросом
     * (вместо N updateOrCreate). Ключи, отсутствующие в SCHEMA, отбрасываются.
     *
     * @param  array<string, array<string, mixed>>  $groups  группа → ключ → значение
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
