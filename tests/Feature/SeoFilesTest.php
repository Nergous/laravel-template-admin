<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** robots.txt, sitemap.xml and the admin noindex header follow the SEO settings. */
class SeoFilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_robots_closes_the_whole_site_when_indexing_is_off(): void
    {
        Setting::set('seo', 'indexable', false);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee("Disallow: /\n", false)
            ->assertDontSee('Sitemap:');
        $this->get('/sitemap.xml')->assertNotFound();
    }

    public function test_robots_closes_the_admin_and_announces_the_sitemap(): void
    {
        Setting::set('seo', 'indexable', true);
        Setting::set('seo', 'sitemap', true);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Disallow: /admin', false)
            ->assertSee('/sitemap.xml', false);
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<urlset', false);

        Setting::set('seo', 'sitemap', false);
        $this->get('/robots.txt')->assertDontSee('Sitemap:');
        $this->get('/sitemap.xml')->assertNotFound();
    }

    public function test_admin_pages_are_marked_noindex(): void
    {
        $this->get(route('login'))->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }
}
