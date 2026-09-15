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

    /** Block tags NRichText emits — unwrapped (text kept), their boundaries → newline. */
    private const BLOCK_TAGS = ['p', 'div', 'span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'blockquote', 'pre'];

    /**
     * @param  string  $html  Source HTML (e.g. from NRichText)
     * @return string Sanitized HTML — only the allowed inline markup, block breaks as \n
     */
    public static function sanitize(string $html): string
    {
        return trim(self::sanitizer()->sanitize(self::blockBreaksToNewlines($html)));
    }

    /**
     * NRichText (tiptap) wraps each line in a block tag (<p>) and uses <br> for soft
     * breaks. symfony/html-sanitizer v8 drops the CONTENT of tags that aren't explicitly
     * allowed, so we turn block boundaries into newlines first; the sanitizer then
     * unwraps the block tags (blockElement) and keeps the text. MAX renders \n as a
     * line break. Without this, a paragraph-wrapped message sanitized to an empty
     * string and could not be saved (text.required).
     */
    private static function blockBreaksToNewlines(string $html): string
    {
        $html = preg_replace('#<br\s*/?>#i', "\n", $html);

        // A newline BEFORE each opening block tag. contenteditable emits a bare first
        // line followed by <div>lines</div> ("a<div>b</div>"); a closing-tag rule would
        // merge "a" and "b". blockElement then unwraps the tag, leaving the injected \n.
        return preg_replace('#<(?:p|div|li|h[1-6]|blockquote|pre)(?:\s[^>]*)?>#i', "\n$0", $html);
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

        // Unwrap block tags (keep their text). In symfony/html-sanitizer v8 an
        // unconfigured element is dropped WITH its content, which would blank out
        // paragraph-wrapped rich-text; blockElement keeps the text and drops only the tag.
        foreach (self::BLOCK_TAGS as $tag) {
            $config = $config->blockElement($tag);
        }

        return new HtmlSanitizer($config);
    }
}
