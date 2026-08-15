<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Demo\DemoMode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Closes a route while this installation is the public demo.
 *
 * Named on the routes themselves (routes/web.php, routes/ai.php) rather
 * than asked for inside the controllers, so the list of what a demo may
 * not do can be read in one place instead of hunted for across nine
 * actions. What is on that list and why is in config/demo.php.
 *
 * A refusal, not a redirect. Sending a visitor back to the dashboard
 * would leave them wondering whether they mis-clicked; the page they get
 * instead says what this installation is and why the thing they wanted
 * is not part of it. Machines get the same answer as JSON, because the
 * service worker and the OAuth clients that reach these routes cannot
 * read a page.
 */
class EnsureNotDemo
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! DemoMode::enabled()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['error' => DemoMode::refusal()], 403);
        }

        // 403 rather than 200: the page is friendly, but the answer is
        // still "no", and a monitor or a crawler reading this route
        // should be told so in the one field it understands.
        return response()->view('demo-locked', status: 403);
    }
}
