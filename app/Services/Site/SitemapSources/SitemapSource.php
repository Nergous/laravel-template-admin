<?php

namespace App\Services\Site\SitemapSources;

/**
 * A source of /sitemap.xml addresses. Registered by tagging it with Sitemap::SOURCES
 * in a service provider (see SiteServiceProvider).
 */
interface SitemapSource
{
    /** @return iterable<SitemapEntry> */
    public function entries(): iterable;
}
