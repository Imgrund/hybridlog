<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

/**
 * Whatever terminates TLS in front of this application, a platform's router
 * or a reverse proxy of your own, forwards over plain HTTP and says so in
 * X-Forwarded-Proto. Trust the wrong address and Laravel reads every request
 * as insecure: OAuth redirects that come back as http:// and no client
 * accepts, a session cookie that never gets the Secure flag.
 *
 * The value lives in configuration and is applied in bootstrap/app.php after
 * the configuration is loaded, which is later than it looks: the middleware
 * callback next to it runs while the kernel is still being built, before
 * config() exists at all.
 */
class TrustedProxyTest extends TestCase
{
    /** @var array<int, string> */
    private array $originalProxies;

    private int $originalHeaders;

    protected function setUp(): void
    {
        parent::setUp();

        // Symfony keeps the trusted proxies on the Request class itself, so
        // running the middleware here outlives this test unless it is put back.
        $this->originalProxies = Request::getTrustedProxies();
        $this->originalHeaders = Request::getTrustedHeaderSet();
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies($this->originalProxies, $this->originalHeaders);

        parent::tearDown();
    }

    public function test_a_proxy_the_configuration_names_may_set_the_scheme(): void
    {
        // The default is loopback, where a proxy on the same machine sits.
        $request = $this->forwardedRequest(from: '127.0.0.1');

        $this->assertTrue($request->isSecure());
    }

    public function test_any_other_address_may_not(): void
    {
        // Anyone who reaches the application directly can send the same
        // header, which is why the default is a list and not a wildcard.
        $request = $this->forwardedRequest(from: '203.0.113.7');

        $this->assertFalse($request->isSecure());
    }

    public function test_a_wildcard_trusts_whoever_is_calling(): void
    {
        // What a container is left with: the platform routes from an address
        // inside its own network that changes with every deploy, and nothing
        // else can reach the container at all. See TRUSTED_PROXIES in fly.toml.
        config(['app.trusted_proxies' => '*']);
        TrustProxies::at(config('app.trusted_proxies'));

        $request = $this->forwardedRequest(from: '198.51.100.42');

        $this->assertTrue($request->isSecure());
    }

    /**
     * A request as it arrives from a TLS-terminating proxy: plain HTTP, with
     * the original scheme in a header.
     */
    private function forwardedRequest(string $from): Request
    {
        $request = Request::create('http://dashboard.test/', server: [
            'REMOTE_ADDR' => $from,
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        app(TrustProxies::class)->handle($request, fn (): Response => new Response);

        return $request;
    }
}
