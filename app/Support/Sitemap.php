<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Registry of public URLs for /sitemap.xml (served while seo.indexable and
 * seo.sitemap are on). The template has no public pages, so the registry starts
 * empty; a project registers its sources in a service provider:
 *
 *   app(Sitemap::class)->add(fn () => Post::published()->get()->map(fn (Post $post) => [
 *       'loc' => '/blog/'.$post->slug,
 *       'lastmod' => $post->updated_at,
 *   ]));
 *
 * A source returns URL strings or arrays with loc (absolute or root-relative),
 * and optional lastmod, changefreq and priority.
 */
final class Sitemap
{
    /** @var list<callable(): iterable<string|array<string, mixed>>> */
    private array $sources = [];

    /** @param callable(): iterable<string|array<string, mixed>> $source */
    public function add(callable $source): void
    {
        $this->sources[] = $source;
    }

    /**
     * Entries with absolute URLs; root-relative locations are resolved against $base.
     *
     * @return list<array{loc: string, lastmod: ?string, changefreq: ?string, priority: ?string}>
     */
    public function entries(string $base): array
    {
        $entries = [];

        foreach ($this->sources as $source) {
            foreach ($source() as $item) {
                $item = is_string($item) ? ['loc' => $item] : $item;
                $loc = (string) ($item['loc'] ?? '');

                if ($loc === '') {
                    continue;
                }

                $lastmod = $item['lastmod'] ?? null;

                $entries[] = [
                    'loc' => str_starts_with($loc, '/') && ! str_starts_with($loc, '//') ? $base.$loc : $loc,
                    'lastmod' => $lastmod instanceof CarbonInterface ? $lastmod->toAtomString() : ($lastmod !== null ? (string) $lastmod : null),
                    'changefreq' => isset($item['changefreq']) ? (string) $item['changefreq'] : null,
                    'priority' => isset($item['priority']) ? (string) $item['priority'] : null,
                ];
            }
        }

        return $entries;
    }
}
