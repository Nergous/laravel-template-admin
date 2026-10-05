<?php

namespace App\Support;

use App\Models\Media;
use Illuminate\Support\Facades\Storage;

/**
 * The single registry of fields that may reference a media file, and the
 * single algorithm that finds those references inside a stored value.
 *
 * Usage labels and edit links (MediaUsage), the deletion guard, the
 * "used / unused" filter, the text usage index (MediaUsageIndex) and link
 * rewriting after a file change (MediaReferenceUpdater) all read these lists:
 * a new media-bearing field is registered here once.
 *
 * A text field or setting links a file by URL: "/storage/media/a.webp", the
 * same path on any host, percent-encoded or not. A link to a thumbnail or a
 * responsive copy counts for the original.
 */
final class MediaReferenceRegistry
{
    /** Settings holding a file URL or HTML with file links: [group, key, label]. */
    public const SETTINGS = [
        ['general', 'favicon', 'Фавикон'],
        ['seo', 'og_image', 'OG-изображение'],
    ];

    /**
     * Foreign keys to media.id. owner — the record the editor opens (a short
     * type name, see MediaUsage::owners()/place()), owner_key — the column
     * holding its id. Example:
     *
     *     ['table' => 'articles', 'column' => 'cover_media_id', 'owner' => 'article', 'owner_key' => 'id', 'label' => 'Обложка статьи'],
     *
     * @var list<array{table: string, column: string, owner: string, owner_key: string, label: string}>
     */
    public const FOREIGN_KEYS = [];

    /**
     * Text columns rendered as HTML or as a link. key — the row key used when
     * rewriting; model — whose saved/deleted events invalidate the usage index
     * (rewrites also go through it, so LogsActivity records them). Example:
     *
     *     [
     *         'table' => 'articles', 'columns' => ['body_html'], 'key' => 'id',
     *         'owner' => 'article', 'owner_key' => 'id', 'label' => 'Текст статьи', 'model' => Article::class,
     *     ],
     *
     * @var list<array{table: string, columns: list<string>, key: string, owner: string, owner_key: string, label: string, model: class-string}>
     */
    public const TEXT_FIELDS = [];

    /**
     * Media filenames (the media.filename value) a stored value links to.
     *
     * @return list<string>
     */
    public static function filenames(mixed $value): array
    {
        if (! is_string($value) || $value === '' || ! preg_match_all(self::pattern(), $value, $matches)) {
            return [];
        }

        $found = [];
        foreach ($matches[2] as $path) {
            $found[self::original(self::decode($path))] = true;
        }

        return array_map('strval', array_keys($found));
    }

    /**
     * Points links at other stored files: $map is old stored path => new
     * stored path (thumbnails and responsive copies listed separately). The
     * host part of a link stays as written.
     *
     * @param  array<string, string>  $map
     */
    public static function replace(string $value, array $map): string
    {
        $base = self::urlBase();

        return preg_replace_callback(self::pattern(), function (array $m) use ($map, $base) {
            $path = self::decode($m[2]);

            return isset($map[$path]) ? $m[1].substr($base, 1).$map[$path] : $m[0];
        }, $value) ?? $value;
    }

    /**
     * Substrings narrowing a database search for links to $filename before
     * filenames()/replace() decide: the path without its extension (matches
     * the thumbnail and responsive copies too), raw and percent-encoded.
     *
     * @return list<string>
     */
    public static function needles(string $filename): array
    {
        $info = pathinfo($filename);
        $dir = ($info['dirname'] ?? '.') !== '.' ? $info['dirname'].'/' : '';
        $stem = $dir.$info['filename'].'.';
        $encoded = implode('/', array_map('rawurlencode', explode('/', $stem)));

        return array_values(array_unique([$stem, $encoded]));
    }

    /** Path prefix of media URLs: "/storage/" for the public disk. */
    public static function urlBase(): string
    {
        $path = (string) parse_url(Storage::disk(Media::diskName())->url('x'), PHP_URL_PATH);

        return '/'.ltrim(substr($path, 0, -1), '/');
    }

    /**
     * Group 1: optional scheme and host plus the leading "/"; group 2: the
     * stored path up to a quote, whitespace, query, fragment or tag bracket.
     */
    private static function pattern(): string
    {
        $base = self::urlBase();

        return '~((?:[a-z][a-z0-9+.-]*:)?(?://[^/"\'\s<>]+)?/)'.preg_quote(substr($base, 1), '~').'([^"\'\s?#<>]+)~iu';
    }

    private static function decode(string $path): string
    {
        return rawurldecode(html_entity_decode($path, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** "media/a.thumb.webp" and "media/a.w960.webp" → "media/a.webp". */
    private static function original(string $path): string
    {
        return preg_replace('/\.(thumb|w\d+)\.(webp|avif)$/', '.$2', $path) ?? $path;
    }
}
