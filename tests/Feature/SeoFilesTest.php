<?php

namespace Tests\Feature;

use App\Services\Site\Sitemap;
use App\Services\Site\SitemapSources\SitemapEntry;
use App\Services\Site\SitemapSources\SitemapSource;
use App\Services\System\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** robots.txt, sitemap.xml and the admin noindex header follow the SEO settings. */
class SeoFilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_robots_closes_the_whole_site_when_indexing_is_off(): void
    {
        app(Settings::class)->set('seo', 'indexable', false);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee("Disallow: /\n", false)
            ->assertDontSee('Sitemap:');
        $this->get('/sitemap.xml')->assertNotFound();
    }

    public function test_robots_closes_the_admin_and_announces_the_sitemap(): void
    {
        app(Settings::class)->set('seo', 'indexable', true);
        app(Settings::class)->set('seo', 'sitemap', true);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Disallow: /admin', false)
            ->assertSee('/sitemap.xml', false);
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<urlset', false);

        app(Settings::class)->set('seo', 'sitemap', false);
        $this->get('/robots.txt')->assertDontSee('Sitemap:');
        $this->get('/sitemap.xml')->assertNotFound();
    }

    public function test_robots_and_sitemap_bodies_are_exact(): void
    {
        app(Settings::class)->set('seo', 'indexable', true);
        app(Settings::class)->set('seo', 'sitemap', true);
        // No source is registered by default; a project tags its own with Sitemap::SOURCES.
        $this->app->instance(TestPagesSource::class, new TestPagesSource);
        $this->app->tag([TestPagesSource::class], Sitemap::SOURCES);

        $this->assertSame(
            "User-agent: *\nDisallow: /admin\n\nSitemap: http://localhost/sitemap.xml\n",
            $this->get('/robots.txt')->getContent(),
        );

        $xml = $this->get('/sitemap.xml')->getContent();
        $this->assertStringStartsWith("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<urlset ", $xml);
        $this->assertStringContainsString("  <url>\n    <loc>http://localhost/a&amp;b</loc>\n", $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function test_sitemap_is_empty_without_sources(): void
    {
        app(Settings::class)->set('seo', 'indexable', true);
        app(Settings::class)->set('seo', 'sitemap', true);

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringNotContainsString('<url>', $xml);
    }

    public function test_admin_pages_are_marked_noindex(): void
    {
        $this->get(route('login'))->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}

/** A sitemap source registered only by this test. */
final class TestPagesSource implements SitemapSource
{
    public function entries(): iterable
    {
        return [new SitemapEntry('/a&b')];
    }
}
