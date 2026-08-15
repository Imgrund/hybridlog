<?php

use App\Http\Middleware\EnsureNotDemo;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Laravel\Passport\Http\Middleware\CheckToken;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'scopes' => CheckToken::class,
            // Named on routes rather than added to a group: which doors a
            // public demo keeps shut is a list worth reading in the route
            // file. See config/demo.php.
            'not-demo' => EnsureNotDemo::class,
        ]);
        $middleware->redirectGuestsTo('/login');

        // Every rendered page picks its language here. Only the web group:
        // the MCP server and the OAuth endpoints speak English to machines,
        // and their wording is part of a contract, not a preference.
        $middleware->web(append: [SetLocale::class]);

        // Which proxy to trust is configured, so it is set further down in
        // booted(), where the configuration exists. This callback runs while
        // the kernel is being built, before any of it has been read.

        // A forged Host header would otherwise land in the OAuth discovery
        // metadata and could point a client at a foreign authorization endpoint.
        // Symfony matches these as regular expressions, so anchor every host:
        // an unanchored "example.test" would also accept "example.test.evil.com".
        $middleware->trustHosts(at: function (): array {
            $hosts = config('app.trusted_hosts');

            if ($hosts === []) {
                $hosts = array_filter([parse_url((string) config('app.url'), PHP_URL_HOST)]);
            }

            return array_map(fn (string $host): string => '^'.preg_quote($host, '#').'$', $hosts);
        }, subdomains: false);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Naming these paths replaces Laravel's default rather than adding
        // to it, so expectsJson() has to come back in by hand: the service
        // worker asks /push/next with an Accept header and needs its 401 as
        // a status it can read, not as a redirect to the login page.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('mcp/*') || $request->expectsJson(),
        );
    })
    // Whatever terminates TLS in front of the app forwards over plain HTTP
    // from an address inside the platform's own network, and it has to be
    // trusted: otherwise Laravel reads the request as plain HTTP and builds
    // http:// URLs, meaning OAuth redirects no client accepts and a session
    // cookie without Secure. See config/app.php for what belongs in
    // TRUSTED_PROXIES.
    ->booted(function (): void {
        TrustProxies::at(config('app.trusted_proxies'));
    })->create();
