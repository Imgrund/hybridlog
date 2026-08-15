<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\User;
use RuntimeException;
use Throwable;

/**
 * Resolves the user the current execution acts for: the tenant every
 * query, model scope and mirror connection underneath it belongs to.
 *
 * Authenticated paths resolve themselves: a web request carries its
 * session user, an MCP-over-HTTP request carries the Passport token's
 * user, and both are read from the auth manager so the answer is the
 * same wherever the asking code happens to run.
 *
 * Console runs have no auth layer, so they say who they act for: the
 * scheduled senders name each athlete in turn through self::for(), and
 * the fetch commands take a --tenant. What is left asking here is the
 * local stdio MCP transport, which is one person at a keyboard with no
 * way to say which account they are, and for that the installation owner
 * is not a fallback but the answer.
 *
 * Everything else fails closed. A web or MCP request without a user
 * never falls back to anybody, least of all tenant #1.
 */
final class ActingUser
{
    /**
     * The tenant a console run has named for itself, if any.
     *
     * @see self::for()
     */
    private static ?User $named = null;

    /**
     * Runs a piece of work as one athlete.
     *
     * This is how the scheduled senders became per tenant. What they
     * compose (readiness, load, the muscle map, an alert threshold)
     * comes from a dozen classes that each read the mirror through
     * App\Garmin\Mirror, and threading a user through all of them would
     * have meant changing every signature between the command and the
     * query. The tenant context is one thing, so it is set in one place,
     * and everything underneath resolves to the same athlete.
     *
     * Restored in a finally rather than at the end, because a sender that
     * throws for one athlete must not leave the next one acting as them.
     * Nesting is allowed and unwinds in order.
     *
     * For the console. A request already carries who it is for, and this
     * would be a way to override it.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function for(User $user, callable $work): mixed
    {
        $previous = self::$named;
        self::$named = $user;

        try {
            return $work();
        } finally {
            self::$named = $previous;
        }
    }

    public static function get(): ?User
    {
        if (self::$named !== null) {
            return self::$named;
        }

        // The api guard first: it is the one an MCP request is
        // authenticated on, and it cannot yield a wrong answer on a web
        // request, where it simply has no token to resolve.
        //
        // Asked one at a time, because the api guard is the one that can
        // fail instead of answering: it builds itself from the OAuth
        // signing keys, and an installation whose keys never reached this
        // container throws on the way to a token that was never there.
        // Behind a single try the session user would go down with it, as
        // the right-hand side of a ?? is never reached once the left one
        // throws, and a broken connector would take the whole dashboard
        // with it. It is the API's problem alone, so it is caught alone.
        $user = self::fromGuard('api') ?? self::fromGuard(null);

        if ($user !== null) {
            return $user;
        }

        return app()->runningInConsole() ? User::owner() : null;
    }

    /**
     * The user one guard resolves, or nobody.
     *
     * A guard that throws answers nobody rather than propagating: this is
     * asked on every page and every tool call, and the caller's question
     * is who is acting, not whether some other authentication mechanism
     * is in working order. What refuses to resolve fails closed, which
     * for a request means no tenant and therefore no data.
     *
     * @param  string|null  $guard  null for the default guard.
     */
    private static function fromGuard(?string $guard): ?User
    {
        try {
            $user = auth()->guard($guard)->user();
        } catch (Throwable) {
            return null;
        }

        return $user instanceof User ? $user : null;
    }

    public static function require(): User
    {
        $user = self::get();

        if ($user === null) {
            throw new RuntimeException(
                'No acting user: this execution has neither an authenticated user nor an installation owner to act for.'
            );
        }

        return $user;
    }
}
