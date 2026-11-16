<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Site\SeoMeta;
use App\Services\Site\Sitemap;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * /sitemap.xml while the SEO settings publish it (SeoMeta::sitemapEnabled).
 * Entries are collected by App\Services\Site\Sitemap.
 */
class SitemapController extends Controller
{
    public function __construct(
        private readonly SeoMeta $seo,
        private readonly Sitemap $sitemap,
    ) {}

    public function __invoke(Request $request): Response
    {
        // A plain 404: an exception would go through the shared handler, which
        // renders an error page (or redirects to the admin panel).
        if (! $this->seo->sitemapEnabled()) {
            return response('', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return response()
            ->view('site.seo.sitemap', ['entries' => $this->sitemap->entries($this->seo->baseUrl($request->getSchemeAndHttpHost()))])
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }
}
