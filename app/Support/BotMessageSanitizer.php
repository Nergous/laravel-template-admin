<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitizes the HTML text of bot messages via symfony/html-sanitizer
 *
 * We keep only the inline markup that MAX understands (format=HTML):
 * bold/italic/strikethrough/underline/monospace and links. Everything else
 * (block/unknown tags, on*-handlers, style, javascript:/data: schemes)
 * is stripped; the text of disallowed tags is preserved, while the contents of
 * script/style are removed entirely.
 */
class BotMessageSanitizer
{
    /** Inline tags allowed in bot texts (MAX/Telegram HTML). */
    private const ALLOWED_INLINE = ['b', 'strong', 'i', 'em', 's', 'strike', 'u', 'code'];

    /** Safe schemes for href in links. */
    private const ALLOWED_LINK_SCHEMES = ['https', 'http', 'mailto', 'tg'];

    /**
     * @param  string  $html  Source HTML (e.g. from NRichText)
     * @return string Sanitized HTML — only the allowed inline markup
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
            // Remove script/style along with their contents, rather than leaving them as text.
            ->dropElement('script')
            ->dropElement('style');

        return new HtmlSanitizer($config);
    }
}
