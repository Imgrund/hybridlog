<?php

use App\Mcp\Servers\GarminHealthServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

/*
|--------------------------------------------------------------------------
| AI / MCP Routes
|--------------------------------------------------------------------------
|
| Two transports for the same server:
|
| - Local stdio (`php artisan mcp:start garmin`) for Claude Code / Claude
|   Desktop on this machine; no auth, never leaves the host.
| - HTTP at /mcp/garmin for claude.ai connectors when the app is hosted:
|   OAuth 2.1 via Passport (authorization code + PKCE, dynamic client
|   registration through Mcp::oauthRoutes), scope mcp:use enforced.
|
*/

Mcp::local('garmin', GarminHealthServer::class);

// OAuth 2.1 discovery (.well-known/*) + dynamic client registration (POST /oauth/register).
Mcp::oauthRoutes();

// The package registers /oauth/register without middleware. Dynamic client
// registration is unauthenticated by spec (RFC 7591), but on a publicly reachable
// host that is an unbounded write path into oauth_clients, so re-register the same
// controller behind a throttle. A connector setup needs one call, not dozens.
//
// And shut entirely on a public demo: registration is what turns a stranger's
// chat client into a client of this installation, and the token it would then
// be granted is a token to an account everybody shares. The other half of the
// flow, /oauth/authorize and /oauth/token, is Passport's own registration and
// gets the same guard from AppServiceProvider.
Route::post('/oauth/register', OAuthRegisterController::class)
    ->middleware(['throttle:oauth-register', 'not-demo']);

// The endpoint itself is closed on a demo too. Nothing can reach it there
// anyway once no client can register, but a token minted before the switch
// was thrown would otherwise keep reading a mirror the demo now shares.
// Before the token check on purpose: a connector that is turned away
// should learn that this installation is a demo, not that its token is
// no good. Everywhere else the guard is a pass-through and the 401 comes
// back exactly as it did.
Mcp::web('/mcp/garmin', GarminHealthServer::class)
    ->middleware(['not-demo', 'auth:api', 'scopes:mcp:use', 'throttle:mcp']);
