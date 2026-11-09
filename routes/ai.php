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

// OAuth 2.1 discovery (.well-known/*). Dynamic client registration is
// registered below rather than by the package: the package's own route has
// no middleware, and registration is unauthenticated by spec (RFC 7591), so
// on a publicly reachable host it is an unbounded write path into
// oauth_clients. Here the same controller sits behind a throttle; a
// connector setup needs one call, not dozens. The route keeps the package's
// name because the discovery document advertises registration_endpoint only
// while a route by that name exists.
Mcp::oauthRoutes(registration: false);

// Registration is shut entirely on a public demo: registration is what turns a stranger's
// chat client into a client of this installation, and the token it would then
// be granted is a token to an account everybody shares. The other half of the
// flow, /oauth/authorize and /oauth/token, is Passport's own registration and
// gets the same guard from AppServiceProvider.
Route::post('/oauth/register', OAuthRegisterController::class)
    ->middleware(['throttle:oauth-register', 'not-demo'])
    ->name('mcp.oauth.register');

// The endpoint itself is closed on a demo too. Nothing can reach it there
// anyway once no client can register, but a token minted before the switch
// was thrown would otherwise keep reading a mirror the demo now shares.
// Before the token check on purpose: a connector that is turned away
// should learn that this installation is a demo, not that its token is
// no good. Everywhere else the guard is a pass-through and the 401 comes
// back exactly as it did.
Mcp::web('/mcp/garmin', GarminHealthServer::class)
    ->middleware(['not-demo', 'auth:api', 'scopes:mcp:use', 'throttle:mcp']);
