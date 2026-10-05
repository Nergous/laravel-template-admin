<?php

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

/** Which X-Forwarded-For sources are trusted (config/trustedproxy.php). */
class AuthTrustedProxiesTest extends TestCase
{
    public function test_unset_variable_trusts_the_local_reverse_proxy_only(): void
    {
        $this->assertSame(['127.0.0.1', '::1'], $this->proxiesFor(''));
        $this->assertSame([], $this->proxiesFor('none'));
        $this->assertSame('*', $this->proxiesFor('*'));
        $this->assertSame(['10.0.0.0/8', '192.168.1.1'], $this->proxiesFor(' 10.0.0.0/8, 192.168.1.1 '));
    }

    public function test_forwarded_address_is_used_only_behind_a_configured_proxy(): void
    {
        config(['trustedproxy.proxies' => ['127.0.0.1', '::1']]);

        $this->assertSame('203.0.113.9', $this->clientIp('127.0.0.1'));
        $this->assertSame('198.51.100.7', $this->clientIp('198.51.100.7'));

        // Read per request from config, so a cached configuration applies too.
        config(['trustedproxy.proxies' => ['10.0.0.0/8']]);

        $this->assertSame('203.0.113.9', $this->clientIp('10.1.2.3'));
        $this->assertSame('127.0.0.1', $this->clientIp('127.0.0.1'));
    }

    private function clientIp(string $remoteAddress): string
    {
        $request = Request::create('/', server: [
            'REMOTE_ADDR' => $remoteAddress,
            'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
        ]);

        return app(TrustProxies::class)->handle($request, fn (Request $request) => response((string) $request->ip()))->getContent();
    }

    /** Evaluates config/trustedproxy.php with the given TRUSTED_PROXIES value. */
    private function proxiesFor(string $value): mixed
    {
        $saved = [$_SERVER['TRUSTED_PROXIES'] ?? null, $_ENV['TRUSTED_PROXIES'] ?? null];
        $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'] = $value;

        try {
            return (require config_path('trustedproxy.php'))['proxies'];
        } finally {
            [$server, $env] = $saved;
            if ($server === null) {
                unset($_SERVER['TRUSTED_PROXIES']);
            } else {
                $_SERVER['TRUSTED_PROXIES'] = $server;
            }
            if ($env === null) {
                unset($_ENV['TRUSTED_PROXIES']);
            } else {
                $_ENV['TRUSTED_PROXIES'] = $env;
            }
        }
    }
}
