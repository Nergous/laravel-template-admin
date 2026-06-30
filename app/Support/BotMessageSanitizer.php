<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Санитизация HTML текста сообщений бота через symfony/html-sanitizer
 *
 * Оставляем только инлайн-разметку, которую понимает MAX (format=HTML):
 * жирный/курсив/зачёркнутый/подчёркнутый/моноширинный и ссылки. Всё остальное
 * (блочные/неизвестные теги, on*-обработчики, style, javascript:/data: схемы)
 * вырезается; текст неразрешённых тегов сохраняется, содержимое script/style —
 * удаляется целиком.
 */
class BotMessageSanitizer
{
    /** Инлайн-теги, разрешённые в текстах бота (MAX/Telegram HTML). */
    private const ALLOWED_INLINE = ['b', 'strong', 'i', 'em', 's', 'strike', 'u', 'code'];

    /** Безопасные схемы для href в ссылках. */
    private const ALLOWED_LINK_SCHEMES = ['https', 'http', 'mailto', 'tg'];

    /**
     * @param  string  $html  Исходный HTML (например, из NRichText)
     * @return string Очищенный HTML — только разрешённая инлайн-разметка
     */
    public static function sanitize(string $html): string
    {
        return self::sanitizer()->sanitize($html);
    }

    private static function sanitizer(): HtmlSanitizer
    {
        $config = new HtmlSanitizerConfig;

        foreach (self::ALLOWED_INLINE as $tag) {
            $config = $config->allowElement($tag);
        }

        $config = $config
            ->allowElement('a', ['href'])
            ->allowLinkSchemes(self::ALLOWED_LINK_SCHEMES)
            // script/style удаляем вместе с содержимым, а не оставляем как текст.
            ->dropElement('script')
            ->dropElement('style');

        return new HtmlSanitizer($config);
    }
}
