User-agent: *
@if ($indexable)
Disallow: /admin
@if ($sitemapUrl)

Sitemap: {!! $sitemapUrl !!}
@endif
@else
Disallow: /
@endif
