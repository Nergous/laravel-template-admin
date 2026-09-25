<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\SeoMeta;
use App\Support\Sitemap;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * robots.txt and sitemap.xml driven by the SEO settings.
 *
 * seo.indexable off closes the whole site to crawlers; on, only the admin
 * panel is closed. seo.sitemap publishes /sitemap.xml (entries come from the
 * App\Support\Sitemap registry) and announces it in robots.txt.
 */
class SeoController extends Controller
{
    public function robots(Request $request): Response
    {
        $seo = Setting::grouped()['seo'];
        $lines = ['User-agent: *'];

        if (! $seo['indexable']) {
            $lines[] = 'Disallow: /';
        } else {
            $lines[] = 'Disallow: /admin';

            if ($seo['sitemap']) {
                $lines[] = '';
                $lines[] = 'Sitemap: '.SeoMeta::baseUrl($request).'/sitemap.xml';
            }
        }

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public function sitemap(Request $request, Sitemap $sitemap): Response
    {
        $seo = Setting::grouped()['seo'];

        // A plain 404: the global handler would redirect unknown public paths to /admin.
        if (! $seo['indexable'] || ! $seo['sitemap']) {
            return response('', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'];

        foreach ($sitemap->entries(SeoMeta::baseUrl($request)) as $entry) {
            $xml[] = '  <url>';
            foreach ($entry as $tag => $value) {
                if ($value !== null) {
                    $xml[] = "    <{$tag}>".htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8')."</{$tag}>";
                }
            }
            $xml[] = '  </url>';
        }

        $xml[] = '</urlset>';

        return response(implode("\n", $xml)."\n", 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
