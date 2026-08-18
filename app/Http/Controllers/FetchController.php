<?php

namespace App\Http\Controllers;

use App\Garmin\FetchTrigger;
use App\Garmin\GarminData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** The manual fetch trigger and the status the page polls. */
class FetchController extends Controller
{
    public function __construct(private GarminData $garmin) {}

    public function fetchNow(Request $request, FetchTrigger $fetch): RedirectResponse
    {
        // The button asks for the same fetch the schedule runs, so logs,
        // environment and single-instance behaviour stay identical. Limited
        // to one start every two minutes: a fetch takes about that long, and
        // the button must not hammer Garmin.
        //
        // Per athlete, because the window is about one Garmin account: a
        // shared limiter would have let whoever pressed first lock everybody
        // else out of their own watch for two minutes.
        $allowed = RateLimiter::attempt(self::limiterKey($request->user()->id), 1, fn () => true, 120);

        if (! $allowed) {
            return redirect()->route('dashboard')->with('fetch_busy', true);
        }

        // The trigger either starts the fetch or says in a sentence why
        // it will not (no Garmin session, the demo). The sentence is the
        // flash: calling that state "busy" would promise a fetch that is
        // never going to happen.
        $refused = $fetch->start();

        if ($refused !== null) {
            return redirect()->route('dashboard')->with('fetch_refused', $refused);
        }

        return redirect()->route('dashboard')->with('fetch_started', true);
    }

    /**
     * The outcome of the fetch the page is waiting on.
     *
     * The flash after "Fetch from Garmin" polls this and reloads once the
     * run is over with a fetch stamp past the one the page was rendered
     * with: the stamp alone moves per endpoint mid-run and is no
     * done-signal by itself. A fetch that dies never writes that
     * stamp, so the failure is reported alongside it: without it the page
     * waited out its four minutes and then blamed the speed for what was
     * a missing Garmin connection.
     */
    public function fetchStatus(FetchTrigger $fetch): JsonResponse
    {
        // No tenant named: every call here is inside a request, so the
        // trigger and the mirror both resolve the athlete asking.
        $failure = $fetch->lastFailure();
        $status = $this->garmin->dataStatus();
        $run = $fetch->currentRun();

        return response()->json([
            'last_fetch' => $this->garmin->latestFetch(),
            // Whether one is under way right now, which is a different
            // question from whether the last one worked: the page uses it
            // to keep waiting, and to say so, across a reload.
            'running' => $run !== null,
            // Day N of M, while the run's window is known. The page shows
            // it for a backfill, where the wait is many minutes and the
            // moving number is the difference between "working" and
            // "who knows"; it also feeds the stall clock either way.
            'progress' => $this->garmin->fetchProgress($run),
            'failed_at' => $failure['at'] ?? null,
            // The short reason where the mirror knows one, the raw
            // message where it does not: a fetch can also die of a
            // timeout or a database that went away, and "it failed"
            // with no reason at all is what this endpoint is fixing.
            // Deliberately not hint, which the header line above is
            // already showing word for word in these very states.
            'problem' => $failure === null ? null : ($status->reason() ?? $failure['message']),
            'state' => $failure === null ? null : $status->state,
            // Both only where signing in is the fix: a fetch that died
            // of a timeout gets no button that cannot help it.
            'action' => $failure === null ? null : $status->signInLabel(),
            'connect_url' => $failure === null || ! $status->needsSignIn() ? null : route('connect.garmin'),
        ]);
    }

    /**
     * The two-minute window, per athlete.
     *
     * Shared with App\Mcp\Tools\RefreshDataTool, so a person and their
     * AI together still start one run per window, which is the point of
     * the limit; two different athletes are two different windows.
     */
    public static function limiterKey(int $tenant): string
    {
        return 'manual-fetch:'.$tenant;
    }
}
