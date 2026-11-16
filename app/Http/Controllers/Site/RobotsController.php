<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Site\SeoMeta;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * robots.txt from the SEO settings: with indexing off the whole site is closed,
 * otherwise only the admin panel; a published sitemap is announced.
 */
class RobotsController extends Controller
{
    public function __construct(private readonly SeoMeta $seo) {}

    public function __invoke(Request $request): Response
    {
        return response()
            ->view('site.seo.robots', [
                'indexable' => $this->seo->indexable(),
                'sitemapUrl' => $this->seo->sitemapEnabled() ? $this->seo->baseUrl($request->getSchemeAndHttpHost()).'/sitemap.xml' : null,
            ])
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
