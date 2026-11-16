<?php

namespace App\Services\Site;

use App\Services\Site\SitemapSources\SitemapSource;

/**
 * Public addresses for /sitemap.xml (served while seo.indexable and seo.sitemap
 * are on). Sources are SitemapSource classes tagged with self::SOURCES (see
 * App\Providers\SiteServiceProvider); none are registered by default.
 */
final class Sitemap
{
    /** Container tag that marks sitemap sources. */
    public const SOURCES = 'sitemap.sources';

    /** @param  iterable<SitemapSource>  $sources */
    public function __construct(private readonly iterable $sources) {}

    /**
     * Entries with absolute URLs; root-relative addresses are prefixed with $base.
     *
     * @return list<array{loc: string, lastmod: ?string, changefreq: ?string, priority: ?string}>
     */
    public function entries(string $base): array
    {
        $entries = [];

        foreach ($this->sources as $source) {
            foreach ($source->entries() as $entry) {
                $entries[] = $entry->toArray($base);
            }
        }

        return $entries;
    }
}
