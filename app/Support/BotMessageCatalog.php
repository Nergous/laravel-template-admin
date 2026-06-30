<?php

namespace App\Support;

/**
 * Читает общий JSON-реестр сообщений бота (config('bot.registry')).
 * Источник правды для кодов/меток/дефолтов; шарится с Python-ботом.
 */
class BotMessageCatalog
{
    /** @var array<int, array{code:string,label:string,description:string,default:string}>|null */
    private static ?array $cache = null;

    /** @return array<int, array{code:string,label:string,description:string,default:string}> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $path = config('bot.registry');
        $raw = is_string($path) && is_file($path) ? file_get_contents($path) : '[]';
        $data = json_decode($raw ?: '[]', true);

        return self::$cache = is_array($data) ? array_values($data) : [];
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_map(fn (array $m) => $m['code'], self::all());
    }

    /**
     * @param  string  $code  Код сообщения из реестра
     * @return array{code:string,label:string,description:string,default:string}|null
     */
    public static function find(string $code): ?array
    {
        foreach (self::all() as $message) {
            if ($message['code'] === $code) {
                return $message;
            }
        }

        return null;
    }
}
