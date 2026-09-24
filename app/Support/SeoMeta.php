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

        $base = rtrim((string) ($seo['canonical_domain'] ?: $request->getSchemeAndHttpHost()), '/');
        $path = trim($request->path(), '/');

        $title = trim((string) $title);
        $template = (string) $seo['meta_title_template'];

        return [
            'title' => $title === ''
                ? $siteName
                : (str_contains($template, '%s') ? str_replace('%s', $title, $template) : $title),
            'description' => trim((string) ($description ?: $seo['meta_description'])),
            'image' => self::absolute($base, (string) ($image ?: $seo['og_image'])),
            'canonical' => $base.($path === '' ? '/' : '/'.$path),
            'robots' => $seo['indexable'] ? null : 'noindex, nofollow',
            'site_name' => $siteName,
            'favicon' => ($settings['general']['favicon'] ?? '') ?: null,
        ];
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
