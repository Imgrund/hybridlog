<?php

declare(strict_types=1);

namespace App\Garmin;

use Illuminate\Support\Facades\DB;

/**
 * Whether an athlete has signed in to Garmin at all.
 *
 * The session rows live in garmin_private.garmin_session, written by the
 * fetcher's login and readable by no tenant's reader role. The scheduled
 * run already asks that table who to fetch for
 * (App\Console\Commands\FetchEveryAthleteCommand); this is the same
 * question for one athlete, asked before a fetch is started on their
 * behalf.
 *
 * A missing session is a state, not a failure: every installation is in
 * it until its first sign-in, and a Quickstart walking through on seeded
 * data stays in it deliberately. Whatever turns a fetch down because of
 * it says so by name, which is what keeps that state out of failed_jobs.
 */
class GarminSession
{
    /**
     * Whether this athlete has a stored Garmin session to fetch with.
     */
    public static function exists(int $tenant): bool
    {
        // One schema over from every mirror, so no tenant's reader may
        // see it: read as the login role, exactly as the scheduled run
        // does.
        Mirror::unpin();

        $connection = DB::connection('garmin');

        // The table arrives with the first provisioned mirror
        // (fetcher/schema.sql carries it), so on an installation where
        // nothing has been provisioned yet the honest answer is already
        // "not connected", not a relation-does-not-exist error.
        $table = $connection->selectOne(
            "select to_regclass('garmin_private.garmin_session') is not null as found"
        );

        if (! (bool) ($table->found ?? false)) {
            return false;
        }

        return $connection->table('garmin_private.garmin_session')
            ->where('id', $tenant)
            ->exists();
    }

    /**
     * The sentence shown wherever that absence turns something down.
     *
     * Word for word the hint DataStatus gives the not_connected state, so
     * the header line, the refusal flash and the MCP answer tell one
     * story and share one translation.
     */
    public static function notConnectedHint(): string
    {
        return __('This installation is not connected to Garmin yet. Sign in to Garmin once on the connection page, then the fetch can run.');
    }
}
