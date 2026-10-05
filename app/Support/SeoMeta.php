<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Http\Request;

/**
 * Meta tags for public pages built from the SEO settings (/admin/settings → SEO).
 *
 * Used by resources/views/public.blade.php. A page may override the title,
 * description, and image; everything else comes from the settings:
 *  - seo.meta_title_template — "%s" is replaced with the page title;
 *  - seo.meta_description, seo.og_image — defaults for description/og:image;
 *  - seo.canonical_domain — base of canonical/og:url (the current host if empty);
 *  - seo.indexable — false adds robots "noindex, nofollow".
 *
 * Values are plain text; the template escapes them on output. The canonical
 * URL keeps ?page=N for pages 2..N of a list, so they are not declared
 * duplicates of the first page; other query parameters are dropped.
 */
class SeoMeta
{
    /**
     * @return array{title: string, description: string, image: ?string, canonical: string, robots: ?string, site_name: string, favicon: ?string}
     */
    public static function for(Request $request, ?string $title = null, ?string $description = null, ?string $image = null): array
    {
        $settings = Setting::grouped();
        $seo = $settings['seo'];
        $siteName = (string) $settings['general']['app_name'];

        $base = self::baseUrl($request);
        $path = trim($request->path(), '/');

        $title = trim((string) $title);
        $template = (string) $seo['meta_title_template'];

        return [
            'title' => $title === ''
                ? $siteName
                : (str_contains($template, '%s') ? str_replace('%s', $title, $template) : $title),
            'description' => trim((string) ($description ?: $seo['meta_description'])),
            'image' => self::absolute($base, (string) ($image ?: $seo['og_image'])),
            'canonical' => $base.($path === '' ? '/' : '/'.$path).self::pageQuery($request),
            'robots' => $seo['indexable'] ? null : 'noindex, nofollow',
            'site_name' => $siteName,
            'favicon' => ($settings['general']['favicon'] ?? '') ?: null,
        ];
    }

    /** Site origin for absolute links: seo.canonical_domain, or the current host when empty. */
    public static function baseUrl(Request $request): string
    {
        $domain = (string) Setting::value('seo', 'canonical_domain');

        return rtrim($domain !== '' ? $domain : $request->getSchemeAndHttpHost(), '/');
    }

    /** "?page=N" suffix of the canonical URL on list pages after the first. */
    private static function pageQuery(Request $request): string
    {
        $page = $request->query('page');

        return is_string($page) && preg_match('/^[1-9]\d{0,5}$/', $page) === 1 && (int) $page > 1
            ? '?page='.(int) $page
            : '';
    }

    /** Makes a root-relative asset path absolute (crawlers need full og:image URLs). */
    private static function absolute(string $base, string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        return str_starts_with($url, '/') && ! str_starts_with($url, '//') ? $base.$url : $url;
    }
}
