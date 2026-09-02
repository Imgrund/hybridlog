<?php

namespace App\Garmin;

use App\Demo\DemoMode;
use App\Jobs\RunGarminFetch;
use App\Tenancy\ActingUser;
use Illuminate\Support\Facades\Cache;

/**
 * Starts a Garmin fetch, and remembers when one died.
 *
 * Two callers ask for a fetch: the dashboard's refresh button and the MCP
 * refresh-data tool. Neither cares how the fetcher gets going, only that
 * it does, so both go through here and a queue worker does the minutes of
 * waiting out of band.
 *
 * The failure half belongs here for the same reason: whoever started a
 * fetch is who has to be told it failed. It travels through the cache
 * rather than a table because the fetcher runs in a different container
 * from the page waiting on it, and a failure only has to survive until
 * somebody reads it.
 *
 * Everything here is per athlete. Both marks used to be one key each,
 * which was right while the installation had one mirror: now a fetch for
 * one athlete would have told every other athlete's page that a run was
 * under way, and handed them its failure to display. So the tenant is
 * part of the key, and one athlete's fetch is invisible to the next.
 */
class FetchTrigger
{
    private const FAILURE_KEY = 'garmin:fetch:last-failure';

    /**
     * Long enough to still be there for whoever comes back later in the
     * day, short enough that it cannot outlive its own truth.
     */
    private const FAILURE_TTL_HOURS = 24;

    /**
     * fetch.py's own --days default: the width of a run nobody narrowed.
     */
    public const DEFAULT_DAYS = 7;

    /**
     * Set from the moment a fetch is asked for until the run reports back,
     * one way or the other.
     *
     * The dashboard used to know about a running fetch only from the flash
     * of the request that started it: the scheduled run, a fetch started
     * from the phone, or the same fetch seen from a second tab were all
     * invisible, and the page showed a stale timestamp with no hint that
     * a newer one was already on its way.
     *
     * The value is what the run set out to do (when it was asked for and
     * the days it will walk), because that is the denominator of the
     * progress line: a first fetch spends many minutes on a quarter of a
     * year, and "day 34 of 90" is what stands between that wait and a page
     * that merely claims something is happening. See currentRun().
     */
    private const RUNNING_KEY = 'garmin:fetch:running';

    /**
     * Asks for a fetch.
     *
     * Returns null when one is on its way, or a short reason it is not.
     * A reason rather than an exception because both callers have a
     * place to put it: the button turns it into the "busy" flash, the
     * MCP tool hands it to the model. Today the dispatch cannot fail,
     * the return type is what lets it start failing later without
     * changing either caller.
     *
     * The job is unique, so a dispatch while one is already queued or
     * running is dropped rather than doubled, and this reports success
     * either way: from the caller's side a fetch is under way in both
     * cases.
     *
     * $backfill is the one case where a fetch reaches further than the
     * usual week: an athlete's first run after connecting Garmin, which
     * fills the ninety days the dashboard opens on. See
     * App\Garmin\GarminLogin. $days is the opposite case, a run that
     * reaches less far: the chat's refresh asks for today and yesterday,
     * because it exists for "now" and the scheduled runs already walk
     * the week. Null leaves the fetcher on its own default.
     */
    public function start(?int $tenant = null, ?string $backfill = null, ?int $days = null): ?string
    {
        // The one gate both doors pass through, which is why the demo is
        // turned away here rather than at each of them: a public demo runs
        // on generated data and is signed in to nobody's Garmin account,
        // so a fetch has nothing to fetch and would leave its failure on
        // the page for whoever visits next.
        if (DemoMode::enabled()) {
            return DemoMode::refusal();
        }

        $tenant = $this->tenant($tenant);

        // Without a stored Garmin session the fetch cannot succeed:
        // fetch.py would launch, find no tokens and exit, and the queue
        // job would turn that exit into a failed_jobs row with a
        // stacktrace, for a state that is expected of every installation
        // that has not signed in yet, the Quickstart above all. Refused
        // here by name instead, before anything is marked as running.
        // The session table rather than dataStatus(), because the status
        // reads fetch_log: a freshly connected athlete still carries the
        // NotConnected mark there until their first fetch, and that
        // first fetch is the backfill this must not block.
        if (! GarminSession::exists($tenant)) {
            return GarminSession::notConnectedHint();
        }

        // A new attempt clears the previous verdict. Otherwise the page
        // that just started a fetch would immediately be shown the
        // failure of the one before it and stop waiting.
        Cache::forget($this->key(self::FAILURE_KEY, $tenant));

        // Marked here rather than in the job, because the wait starts now:
        // a job sitting in a queue with no worker to take it is exactly
        // the case a reader needs told, and the job would not be running
        // to say so. It expires on its own, so a worker killed mid-run
        // cannot leave the page claiming a fetch forever.
        Cache::put($this->key(self::RUNNING_KEY, $tenant), [
            'started' => now()->toIso8601String(),
            // The days this run will walk, oldest first. The default is
            // not repeated from anywhere: it is fetch.py's own (--days 7,
            // so start = today - 6), mirrored here because the fetcher
            // never says what it set out to do, only what it has done.
            'from' => $backfill ?? now()->subDays(($days ?? self::DEFAULT_DAYS) - 1)->toDateString(),
            'to' => now()->toDateString(),
            'backfill' => $backfill !== null,
        ], now()->addSeconds(self::runningTtl()));

        RunGarminFetch::dispatch($tenant, $backfill, $days);

        return null;
    }

    /**
     * Whether a fetch has been asked for and has not reported back yet.
     */
    public function isRunning(?int $tenant = null): bool
    {
        return Cache::get($this->key(self::RUNNING_KEY, $this->tenant($tenant))) !== null;
    }

    /**
     * The run currently under way (when it was asked for and the days it
     * set out to cover), or null while none is.
     *
     * @return array{started: string, from: ?string, to: ?string, backfill: bool}|null
     */
    public function currentRun(?int $tenant = null): ?array
    {
        $run = Cache::get($this->key(self::RUNNING_KEY, $this->tenant($tenant)));

        if ($run === null) {
            return null;
        }

        // A mark written before the value carried the window, surviving a
        // deploy mid-run: still a running fetch, just one whose reach is
        // unknown, so it gets the plain waiting line and no progress.
        if (! is_array($run)) {
            return ['started' => (string) $run, 'from' => null, 'to' => null, 'backfill' => false];
        }

        return $run;
    }

    /**
     * Marks the current run as over, whichever way it ended.
     */
    public function finish(?int $tenant = null): void
    {
        Cache::forget($this->key(self::RUNNING_KEY, $this->tenant($tenant)));
    }

    /**
     * How long the running mark survives without anyone clearing it.
     *
     * The job's own ceiling plus a margin: past that the fetcher has been
     * killed by its timeout, and whatever the page is still waiting for
     * is not coming.
     */
    private static function runningTtl(): int
    {
        return (int) config('garmin.fetch.timeout') + 120;
    }

    /**
     * Records that a dispatched fetch ended without writing any data.
     *
     * The message is the raw exception, kept short: the readable version
     * comes from data_status, which knows the difference between "not
     * connected yet" and "the session expired". This value's real job is
     * to change, so a page polling for an outcome sees one arrive.
     */
    public function recordFailure(string $message, ?int $tenant = null): void
    {
        Cache::put($this->key(self::FAILURE_KEY, $this->tenant($tenant)), [
            'at' => now()->toIso8601String(),
            'message' => trim(mb_substr($message, 0, 500)),
        ], now()->addHours(self::FAILURE_TTL_HOURS));
    }

    /**
     * The last failed fetch as ['at' => ISO 8601, 'message' => string],
     * or null while nothing has failed.
     */
    public function lastFailure(?int $tenant = null): ?array
    {
        $failure = Cache::get($this->key(self::FAILURE_KEY, $this->tenant($tenant)));

        return is_array($failure) ? $failure : null;
    }

    /**
     * Whose fetch this is.
     *
     * Named outright by the queue job, which has no request to read it
     * off, and resolved from the acting user everywhere else, which is
     * every caller inside a request: the button and the MCP tool both
     * mean the athlete asking.
     */
    private function tenant(?int $tenant): int
    {
        return $tenant ?? ActingUser::require()->id;
    }

    private function key(string $key, int $tenant): string
    {
        return $key.':'.$tenant;
    }
}
