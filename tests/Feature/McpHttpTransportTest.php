<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\GarminHealthServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The hosted transport, which until now was verified only by a one-off
 * manual replay against claude.ai. Everything here is what a remote
 * connector depends on and what nothing else fails on when it breaks:
 * discovery, the 401 contract, the scope gate, the throttles, and the
 * one invariant that keeps registration rate-limited.
 */
class McpHttpTransportTest extends TestCase
{
    use RefreshDatabase;

    /** A JSON-RPC call as a connector makes it. */
    private function rpc(string $method, array $params = [], int $id = 1): TestResponse
    {
        return $this->postJson('/mcp/garmin', [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params === [] ? (object) [] : $params,
        ]);
    }

    public function test_a_tokenless_call_gets_json_401_with_the_discovery_pointer(): void
    {
        $response = $this->rpc('tools/list');

        // JSON, never a login redirect: shouldRenderJsonWhen covers mcp/*.
        $response->assertUnauthorized();

        // The WWW-Authenticate header is what makes a connector find the
        // authorization server at all (RFC 9728); without resource_metadata
        // the OAuth flow never starts and the failure looks like a dead URL.
        $header = (string) $response->headers->get('WWW-Authenticate');
        $this->assertStringContainsString('Bearer', $header);
        $this->assertStringContainsString('resource_metadata', $header);
    }

    public function test_a_token_without_the_scope_is_refused(): void
    {
        Passport::actingAs(User::factory()->create(), ['something-else'], 'api');

        $this->rpc('tools/list')->assertForbidden();
    }

    public function test_every_tool_fits_on_the_first_list_page(): void
    {
        Passport::actingAs(User::factory()->create(), ['mcp:use'], 'api');

        $response = $this->rpc('tools/list');

        $response->assertOk();

        // The package paginates at 15 by default and this server carries
        // more tools than that. A client that never follows nextCursor
        // (and several do not) must still see every tool, so the first
        // page has to hold the full set.
        $tools = $response->json('result.tools');
        $registered = (new \ReflectionClass(GarminHealthServer::class))
            ->getDefaultProperties()['tools'];

        $this->assertNotNull($tools, 'tools/list did not answer with a tool list');
        $this->assertCount(count($registered), $tools);
        $this->assertNull($response->json('result.nextCursor'));
    }

    public function test_discovery_names_this_resource_and_its_authorization_server(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource/mcp/garmin')
            ->assertOk()
            ->assertJsonPath('resource', url('/mcp/garmin'))
            ->assertJsonPath('authorization_servers.0', url('/'));

        $this->getJson('/.well-known/oauth-authorization-server')
            ->assertOk()
            ->assertJsonPath('issuer', url('/'))
            ->assertJsonPath('authorization_endpoint', url('/oauth/authorize'))
            ->assertJsonPath('token_endpoint', url('/oauth/token'))
            ->assertJsonPath('registration_endpoint', url('/oauth/register'));
    }

    public function test_the_mcp_endpoint_carries_auth_scope_and_throttle(): void
    {
        // The stack is the security model of the hosted transport; losing
        // any layer in a refactor must fail loudly, not quietly.
        $middleware = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'mcp/garmin' && in_array('POST', $route->methods(), true))
            ->gatherMiddleware();

        $this->assertContains('auth:api', $middleware);
        $this->assertContains('scopes:mcp:use', $middleware);
        $this->assertContains('throttle:mcp', $middleware);
    }

    public function test_client_registration_is_throttled_not_open(): void
    {
        // routes/ai.php re-registers POST /oauth/register with the
        // throttle, relying on Laravel keeping the later registration.
        // If a laravel/mcp upgrade reorders that, the package's
        // unthrottled route wins silently, so this is the tripwire.
        $middleware = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'oauth/register' && in_array('POST', $route->methods(), true))
            ->gatherMiddleware();

        $this->assertContains('throttle:oauth-register', $middleware);

        // And the limiter really answers 429 once the hour's budget is
        // spent: ten registrations are more than one connector setup needs.
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/oauth/register', [
                'client_name' => 'Probe '.$i,
                'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
            ])->assertStatus(201);
        }

        $this->postJson('/oauth/register', [
            'client_name' => 'One too many',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ])->assertStatus(429);
    }

    public function test_registration_refuses_redirects_outside_the_pinned_domains(): void
    {
        // The config pins the domains this installation actually serves;
        // a stranger must not be able to register a client that sends an
        // authorization code to a host of their choosing.
        $this->postJson('/oauth/register', [
            'client_name' => 'Attacker',
            'redirect_uris' => ['https://evil.example/callback'],
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');

        // The loopback entry keeps the native flow alive: Claude Code and
        // Claude Desktop catch their callback on 127.0.0.1 with a random
        // port (RFC 8252), so this exact shape has to keep working.
        $this->postJson('/oauth/register', [
            'client_name' => 'Claude Code (garmin-health)',
            'redirect_uris' => ['http://127.0.0.1:3118/callback'],
        ])->assertStatus(201);

        // LM Studio catches its callback on the same loopback, but on a
        // port it never varies. Pinned here because a future tightening of
        // the loopback rule to "random port only" would lock it out.
        $this->postJson('/oauth/register', [
            'client_name' => 'LM Studio',
            'redirect_uris' => ['http://127.0.0.1:33389/mcp-oauth-callback'],
        ])->assertStatus(201);

        // Langdock mints one callback per integration, so the allowlist
        // pins the host and the path is whatever that integration's id
        // makes it. A neighbouring host must still be refused.
        $this->postJson('/oauth/register', [
            'client_name' => 'Langdock',
            'redirect_uris' => ['https://app.langdock.com/api/integrations/41026a24-614a-4464-85e8-d32360cba375/callback'],
        ])->assertStatus(201);

        $this->postJson('/oauth/register', [
            'client_name' => 'Not Langdock',
            'redirect_uris' => ['https://app.langdock.com.evil.example/callback'],
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');

        // Mistral registers under this name, from Le Chat (since renamed
        // Vibe) and from Studio alike, and takes delivery on
        // callback.mistral.ai, a host it documents nowhere. Both strings
        // are here so the next reader can see what the allowlist entry is
        // for rather than inferring it from a domain that looks like a
        // slip of the pen.
        $this->postJson('/oauth/register', [
            'client_name' => 'mistral-mcp-client',
            'redirect_uris' => ['https://callback.mistral.ai/v1/integrations_auth/oauth2_callback'],
        ])->assertStatus(201);

        // chat.mistral.ai is where the chat is used, not where its codes
        // are delivered. Pinned as a refusal so widening the entry to the
        // whole of mistral.ai has to be a deliberate act.
        $this->postJson('/oauth/register', [
            'client_name' => 'Not Mistral',
            'redirect_uris' => ['https://chat.mistral.ai/mcp/callback'],
        ])->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
    }
}
