<?php

namespace App\Http\Controllers;

use App\Demo\DemoMode;
use App\Mcp\ConnectorAddress;
use Illuminate\Contracts\View\View;

/**
 * The page to send somebody who has just been given an account.
 *
 * Public, and deliberately not behind the login: it is read before the
 * first sign-in, which is the moment it is needed. It holds no data of
 * anybody's and names no athlete, so there is nothing here a stranger
 * could learn beyond the address of a connector that answers 401
 * without a grant anyway.
 *
 * The connector address is resolved per request rather than baked into
 * the route, because it follows the host the reader arrived on.
 */
class SetupController extends Controller
{
    public function __invoke(): View
    {
        // The public demo has no setup to walk anybody through: three of
        // the four steps are closed there (EnsureNotDemo), and the
        // connector answers 403 rather than the 401 that starts a
        // sign-in. So the address is not resolved at all instead of
        // being printed beside a door that does not open.
        if (DemoMode::enabled()) {
            return view('setup', [
                'demoMode' => true,
                'mcpUrl' => null,
                'claudeAddUrl' => null,
            ]);
        }

        $mcpUrl = ConnectorAddress::url();

        return view('setup', [
            'demoMode' => false,
            'mcpUrl' => $mcpUrl,
            'claudeAddUrl' => ConnectorAddress::claudeAddUrl($mcpUrl),
        ]);
    }
}
