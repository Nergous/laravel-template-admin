<?php

namespace App\Services\Site;

use App\Services\System\Settings;

/**
 * SEO settings of the public site (/admin/settings → SEO): page meta tags,
 * whether search engines may index the site and whether /sitemap.xml is published.
 *
 * Meta tags reach resources/views/public.blade.php via SeoMetaComposer. A page
 * may override the title, description and image; the rest comes from the
 * settings:
 *  - seo.meta_title_template — "%s" is replaced with the page title;
 *  - seo.meta_description, seo.og_image — defaults for description/og:image;
 *  - seo.canonical_domain — base of canonical/og:url (empty — the current host);
 *  - seo.indexable — false adds robots "noindex, nofollow".
 *
 * Values are plain text; the template escapes them on output. The canonical URL
 * keeps ?page=N for list pages 2..N so they are not declared duplicates of the
 * first; other query parameters are dropped.
 */
class SeoMeta
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * Page meta tags. $requestBase — the request scheme and host
     * (https://host), $path — the page path, $page — the request's page parameter.
     *
     * @return array{title: string, description: string, image: ?string, canonical: string, robots: ?string, site_name: string, favicon: ?string}
     */
    public function for(string $requestBase, string $path, mixed $page, ?string $title = null, ?string $description = null, ?string $image = null): array
    {
        $seo = $this->settings->group('seo');
        $general = $this->settings->group('general');
        $siteName = (string) $general['app_name'];

        $base = $this->baseUrl($requestBase);
        $path = trim($path, '/');

        $title = trim((string) $title);
        $template = (string) $seo['meta_title_template'];

        return [
            'title' => $title === ''
                ? $siteName
                : (str_contains($template, '%s') ? str_replace('%s', $title, $template) : $title),
            'description' => trim((string) ($description ?: $seo['meta_description'])),
            'image' => $this->absolute($base, (string) ($image ?: $seo['og_image'])),
            'canonical' => $base.($path === '' ? '/' : '/'.$path).$this->pageQuery($page),
            'robots' => $this->indexable() ? null : 'noindex, nofollow',
            'site_name' => $siteName,
            'favicon' => ($general['favicon'] ?? '') ?: null,
        ];
    }

    /** seo.indexable: search engines may index the public site. */
    public function indexable(): bool
    {
        return (bool) $this->settings->value('seo', 'indexable');
    }

    /** /sitemap.xml is published only while seo.sitemap is on and the site is indexable. */
    public function sitemapEnabled(): bool
    {
        return $this->indexable() && (bool) $this->settings->value('seo', 'sitemap');
    }

    /** Site URL for absolute links: seo.canonical_domain, or $requestBase (request scheme and host) when empty. */
    public function baseUrl(string $requestBase): string
    {
        $domain = (string) $this->settings->value('seo', 'canonical_domain');

        return rtrim($domain !== '' ? $domain : $requestBase, '/');
    }

    /** The "?page=N" suffix of the canonical URL on list pages after the first. */
    private function pageQuery(mixed $page): string
    {
        return is_string($page) && preg_match('/^[1-9]\d{0,5}$/', $page) === 1 && (int) $page > 1
            ? '?page='.(int) $page
            : '';
    }

    /** Makes a root-relative path absolute (search engines need full og:image URLs). */
    private function absolute(string $base, string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        return str_starts_with($url, '/') && ! str_starts_with($url, '//') ? $base.$url : $url;
    }
}
