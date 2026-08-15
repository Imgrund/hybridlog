<?php

declare(strict_types=1);

namespace App\Demo;

/**
 * Whether this installation is the public demo, and the sentence every
 * closed door says.
 *
 * One place to ask, because the answer decides things in four different
 * corners of the app: a middleware in front of a dozen routes, two queue
 * jobs, the fetch trigger, the push signer and the header. Spread as
 * `config('demo.enabled')` reads at each of those, the reasoning behind
 * the switch would live nowhere; see config/demo.php for what it is.
 */
final class DemoMode
{
    public static function enabled(): bool
    {
        return (bool) config('demo.enabled');
    }

    /**
     * Why the thing that was just asked for does not happen here.
     *
     * One sentence, because the places that use it have room for one: a
     * JSON body, an MCP tool answer, the error on a sign-in attempt. The
     * page a browser gets says the same thing at length
     * (resources/views/demo-locked.blade.php).
     */
    public static function refusal(): string
    {
        return __('This is the public demo, where everybody signs in to the same account, so anything that connects an account, sends to a device or fetches from Garmin is switched off here.');
    }
}
