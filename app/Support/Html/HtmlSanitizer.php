<?php

namespace App\Support\Html;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Allow-list sanitizer for rich-text HTML.
 *
 * Imported or pasted texts may hold images, tables and alignment, so the list
 * is wider than the admin editor's features. Other tags are unwrapped (their
 * text stays), dangerous ones (script and the like) are removed with their
 * content. URLs are checked by SafeUrl, styles by SafeStyle. Apply it when
 * saving content and again when printing it (a safety net); in Blade use the
 *
 * @html, @htmlText, @htmlWithoutLinks and @htmlInline directives
 * (SiteServiceProvider).
 *
 * Parsing follows the HTML5 fragment algorithm in the <body> context, like
 * innerHTML in a browser: stray closing tags (</div>, </body>, </html>) are
 * skipped and do not cut off the rest of the text.
 *
 * Results are memoized per request by text hash and VERSION, and long texts
 * also in the application cache, so printing the same text again does not
 * re-parse it.
 */
#[Scoped]
final class HtmlSanitizer
{
    /** Bump on any rule change: cache keys are built from it. */
    public const VERSION = 2;

    /** Texts of this length and longer are also kept in the application cache. */
    private const CACHE_MIN_LENGTH = 512;

    private const CACHE_TTL = 60 * 60 * 24 * 30;

    /** Request memo cap: a long-running console command will not grow it without bound. */
    private const MEMO_LIMIT = 256;

    /** Allowed tag => allowed attributes. */
    private const TAGS = [
        'p' => [], 'br' => [], 'span' => [], 'div' => [], 'hr' => [],
        'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 's' => [], 'strike' => [],
        'sub' => [], 'sup' => [], 'small' => [],
        'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'ul' => [], 'ol' => ['start'], 'li' => [],
        'blockquote' => [], 'code' => [], 'pre' => [],
        'a' => ['href', 'title', 'target', 'name'],
        'img' => ['src', 'alt', 'title', 'width', 'height'],
        'figure' => [], 'figcaption' => [],
        'table' => ['border', 'cellpadding', 'cellspacing', 'width'],
        'caption' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [],
        'th' => ['colspan', 'rowspan', 'scope', 'width'],
        'td' => ['colspan', 'rowspan', 'width'],
    ];

    /** Attributes that accept only a number (up to 4 digits), optionally with %. */
    private const NUMERIC_ATTRIBUTES = ['width', 'height', 'colspan', 'rowspan', 'start', 'border', 'cellpadding', 'cellspacing'];

    /** Elements removed together with their content. */
    private const DROP = [
        'script', 'style', 'noscript', 'iframe', 'object', 'embed', 'template', 'link', 'meta',
        'head', 'title', 'base', 'form', 'input', 'button', 'textarea', 'select', 'svg', 'math', 'frame', 'frameset',
    ];

    /** Tags kept in an inline fragment (cleanInline). */
    private const INLINE_TAGS = ['a', 'br', 'b', 'strong', 'i', 'em', 'span'];

    /** @var array<string, string> */
    private array $memo = [];

    public function __construct(private readonly CacheRepository $cache) {}

    public function clean(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        if (trim($html) === '') {
            return '';
        }

        $key = 'html-sanitizer:'.self::VERSION.':'.sha1($html);

        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $clean = strlen($html) >= self::CACHE_MIN_LENGTH
            ? $this->cache->remember($key, self::CACHE_TTL, fn () => $this->sanitize($html))
            : null;

        $clean = is_string($clean) ? $clean : $this->sanitize($html);

        if (count($this->memo) >= self::MEMO_LIMIT) {
            $this->memo = [];
        }

        return $this->memo[$key] = $clean;
    }

    /**
     * Cleans a field that holds either HTML or plain text (a textarea): HTML is
     * sanitized, plain text stays as entered because it is printed escaped via
     * cleanText().
     */
    public function cleanMixed(?string $value): ?string
    {
        if ($value === null || ! self::looksLikeHtml($value)) {
            return $value;
        }

        return $this->clean($value);
    }

    /**
     * Cleans a field with HTML or plain text with line breaks: plain text
     * line breaks become <br>.
     */
    public function cleanText(?string $value): ?string
    {
        if ($value === null || self::looksLikeHtml($value)) {
            return $this->clean($value);
        }

        return $this->clean(nl2br(htmlspecialchars(trim($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false));
    }

    /** Cleans HTML and strips links, keeping their text: for text inside another link. */
    public function cleanWithoutLinks(?string $html): ?string
    {
        $clean = $this->clean($html);

        return $clean === null ? null : preg_replace('#</?a\b[^>]*>#i', '', $clean);
    }

    /** Like cleanText(), but keeps only inline tags (INLINE_TAGS): for use inside a paragraph. */
    public function cleanInline(?string $value): ?string
    {
        $clean = $this->cleanText($value);

        return $clean === null ? null : strip_tags($clean, self::INLINE_TAGS);
    }

    private static function looksLikeHtml(string $value): bool
    {
        return preg_match('/<[a-z][^>]*>/i', $value) === 1;
    }

    private function sanitize(string $html): string
    {
        $source = HTMLDocument::createEmpty('UTF-8');
        $root = $source->createElement('body');
        $source->appendChild($root);
        // Parse the fragment in the <body> context, exactly like element.innerHTML.
        $root->innerHTML = $html;

        $target = new DOMDocument('1.0', 'UTF-8');
        $container = $target->createElement('div');
        $target->appendChild($container);
        self::copyChildren($root, $container, $target);

        $out = '';
        foreach ($container->childNodes as $child) {
            $out .= $target->saveHTML($child);
        }

        return trim($out);
    }

    private static function copyChildren(Node $from, DOMNode $to, DOMDocument $doc): void
    {
        foreach (iterator_to_array($from->childNodes) as $node) {
            if ($node->nodeType === XML_TEXT_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
                $to->appendChild($doc->createTextNode($node->textContent));

                continue;
            }

            if (! $node instanceof Element) {
                continue;
            }

            // Foreign elements (SVG/MathML) never pass, even when named like HTML tags.
            if ($node->namespaceURI !== 'http://www.w3.org/1999/xhtml') {
                continue;
            }

            $tag = strtolower($node->localName);

            if (in_array($tag, self::DROP, true)) {
                continue;
            }

            if (! array_key_exists($tag, self::TAGS)) {
                self::copyChildren($node, $to, $doc);

                continue;
            }

            $clean = $doc->createElement($tag);
            self::copyAttributes($node, $clean, $tag);

            if ($tag === 'img' && ! $clean->hasAttribute('src')) {
                continue;
            }

            self::copyChildren($node, $clean, $doc);
            $to->appendChild($clean);
        }
    }

    private static function copyAttributes(Element $from, DOMElement $to, string $tag): void
    {
        foreach (self::TAGS[$tag] as $name) {
            if (! $from->hasAttribute($name)) {
                continue;
            }

            $value = self::attribute($name, trim($from->getAttribute($name)));

            if ($value !== '') {
                $to->setAttribute($name, $value);
            }
        }

        if ($tag === 'a' && $to->getAttribute('target') === '_blank') {
            $to->setAttribute('rel', 'noopener noreferrer');
        }

        $style = SafeStyle::filter((string) $from->getAttribute('style'));
        if ($style !== '') {
            $to->setAttribute('style', $style);
        }
    }

    /** The checked attribute value; an empty string means the attribute is dropped. */
    private static function attribute(string $name, string $value): string
    {
        return match (true) {
            $name === 'href' => SafeUrl::link($value),
            $name === 'src' => SafeUrl::image($value),
            $name === 'target' => $value === '_blank' ? '_blank' : '',
            in_array($name, self::NUMERIC_ATTRIBUTES, true) => preg_match('/^\d{1,4}%?$/', $value) === 1 ? $value : '',
            default => $value,
        };
    }
}
