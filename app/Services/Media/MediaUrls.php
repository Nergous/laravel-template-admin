<?php

namespace App\Services\Media;

use App\Models\Media;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;

/**
 * Links to media library files inside stored values (HTML, setting URLs).
 *
 * A value links a file by URL: "/storage/media/a.webp", the same path on any
 * host, percent-encoded or not. A link to a thumbnail or a responsive copy
 * counts for the original.
 */
class MediaUrls
{
    /** @var array<string, string> Path prefix per disk name: read for every row, computed once */
    private array $bases = [];

    public function __construct(private readonly FilesystemFactory $storage) {}

    /**
     * Media filenames (the media.filename value) a stored value links to.
     *
     * @return list<string>
     */
    public function filenames(mixed $value): array
    {
        if (! is_string($value) || $value === '' || ! preg_match_all($this->pattern(), $value, $matches)) {
            return [];
        }

        $found = [];
        foreach ($matches[2] as $path) {
            $found[$this->original($this->decode($path))] = true;
        }

        return array_map('strval', array_keys($found));
    }

    /**
     * Points links at other files: $map is old stored path => new stored path
     * (thumbnails and responsive copies listed separately). The host part of a
     * link stays as written.
     *
     * @param  array<string, string>  $map
     */
    public function replace(string $value, array $map): string
    {
        $base = $this->base();

        return preg_replace_callback($this->pattern(), function (array $m) use ($map, $base) {
            $path = $this->decode($m[2]);

            return isset($map[$path]) ? $m[1].substr($base, 1).$map[$path] : $m[0];
        }, $value) ?? $value;
    }

    /**
     * Substrings narrowing a database search for links to $filename before
     * filenames() and replace() decide: the path without its extension (matches
     * the thumbnail and the responsive copies too), raw and percent-encoded.
     *
     * @return list<string>
     */
    public function needles(string $filename): array
    {
        $info = pathinfo($filename);
        $dir = ($info['dirname'] ?? '.') !== '.' ? $info['dirname'].'/' : '';
        $stem = $dir.$info['filename'].'.';
        $encoded = implode('/', array_map('rawurlencode', explode('/', $stem)));

        return array_values(array_unique([$stem, $encoded]));
    }

    /** Path prefix of media URLs: "/storage/" for the public disk. */
    private function base(): string
    {
        $disk = Media::diskName();

        if (! isset($this->bases[$disk])) {
            $path = (string) parse_url($this->storage->disk($disk)->url('x'), PHP_URL_PATH);
            $this->bases[$disk] = '/'.ltrim(substr($path, 0, -1), '/');
        }

        return $this->bases[$disk];
    }

    /**
     * Group 1: optional scheme and host plus the leading "/"; group 2: the
     * stored path up to a quote, whitespace, query, fragment or tag bracket.
     */
    private function pattern(): string
    {
        return '~((?:[a-z][a-z0-9+.-]*:)?(?://[^/"\'\s<>]+)?/)'.preg_quote(substr($this->base(), 1), '~').'([^"\'\s?#<>]+)~iu';
    }

    private function decode(string $path): string
    {
        return rawurldecode(html_entity_decode($path, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** "media/a.thumb.webp" and "media/a.w960.webp" → "media/a.webp". */
    private function original(string $path): string
    {
        return preg_replace('/\.(thumb|w\d+)\.(webp|avif)$/', '.$2', $path) ?? $path;
    }
}
