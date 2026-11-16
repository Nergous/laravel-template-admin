<?php

namespace Tests\Feature;

use App\Services\System\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_static_security_headers_are_present_on_responses(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-XSS-Protection', '0');
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_hsts_is_sent_on_secure_requests(): void
    {
        $this->get('https://localhost/admin/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_csp_is_not_sent_outside_production(): void
    {
        $this->get(route('login'))->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_csp_with_per_request_nonce_is_sent_in_production(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $csp = $this->get(route('login'))->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp, 'CSP должен присутствовать в production');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9+\/=]+'/", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
    }

    public function test_csp_allows_an_external_favicon_host_only_when_configured(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $csp = $this->get(route('login'))->headers->get('Content-Security-Policy');
        $this->assertStringNotContainsString('cdn.example.test', $csp);
        $this->assertStringContainsString("connect-src 'self';", $csp);

        app(Settings::class)->setMany(['general' => ['favicon' => 'https://cdn.example.test/favicon.svg']]);

        $csp = $this->get(route('login'))->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression('~img-src [^;]*https://cdn\.example\.test~', $csp);
        $this->assertMatchesRegularExpression("~script-src 'self' 'nonce-[^']+';~", $csp);
    }

    public function test_csp_allows_external_https_images_of_editorial_html(): void
    {
        app()->detectEnvironment(fn () => 'production');

        $csp = $this->get(route('login'))->headers->get('Content-Security-Policy');

        // HtmlSanitizer keeps external http(s) images (upgraded to https), so the policy must allow them.
        $this->assertMatchesRegularExpression("~img-src 'self' data: https:( |;)~", $csp);
    }
}
