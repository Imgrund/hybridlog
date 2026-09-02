<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Demo\DemoMode;
use App\Garmin\FetchTrigger;
use App\Garmin\GarminData;
use App\Garmin\GarminSession;
use App\Http\Controllers\FetchController;
use App\Mcp\Concerns\ChecksConnectorPermissions;
use App\Mcp\LoggedTool;
use App\Tenancy\ActingUser;
use Carbon\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description(
    'Trigger a fresh Garmin Connect sync of today and yesterday and wait for it to finish (the '.
    'same job as the dashboard\'s refresh button, narrowed to two days: the scheduled runs cover '.
    'the week, this one exists for "now"). Use when the user asks about today and last_fetch is '.
    'stale (e.g. after a workout). The call blocks until the run is over, up to about half a '.
    'minute per call; a still_running answer means the run needs longer, so call this tool again '.
    'to keep waiting, that never starts a second sync. completed means the whole run is done, '.
    'activities included, so re-query and answer with the fresh numbers instead of asking the '.
    'user to come back. A not_started answer means the previous run only just finished and the '.
    'start window has not reopened: use the data as it is, or wait retry_in_seconds and call '.
    'again if something the user expects is still missing. Refuses while there is no working '.
    'Garmin session (data_status not_connected or auth_broken): fetching cannot succeed then, and '.
    'the answer carries the URL where the user signs in.'
)]
#[IsDestructive(false)]
#[IsIdempotent]
class RefreshDataTool extends LoggedTool
{
    use ChecksConnectorPermissions;

    /**
     * Today and yesterday, no further.
     *
     * A refresh from the chat used to walk the fetcher's full week, and
     * at half a minute of wait per call that was call after call of
     * still_running before an answer: the telemetry showed the model
     * polling through a run it only needed the last day of. The
     * scheduled runs walk the week several times a day; this one exists
     * for the workout just finished, and yesterday only because a late
     * session and a night's sleep straddle midnight.
     */
    private const DAYS = 2;

    public function execute(Request $request, GarminData $garmin, FetchTrigger $fetch): Response
    {
        // Before the permission switch, because this one is not the user's
        // to set: a public demo is signed in to no Garmin account, and
        // every number it shows was generated. Answered rather than
        // errored, in the same shape as the missing-session case below, so
        // the model can say what is going on instead of retrying.
        if (DemoMode::enabled()) {
            return Response::json([
                'started' => false,
                'reason' => DemoMode::refusal(),
            ]);
        }

        // This is the one tool that starts a process on the user's machine,
        // so it answers to the same switchboard as every read tool.
        if ($deny = $this->denyUnless($this->settings()->allow_refresh, 'allow_refresh')) {
            return $deny;
        }

        // A fetch without a working Garmin session burns the rate-limit
        // window for nothing; say what is actually wrong instead, and
        // hand over the one place where it can be put right. The session
        // table is asked as well as the status, because the status reads
        // fetch_log: a never-connected installation with no fetch on
        // record yet says fetch_stale there, and the tool used to start
        // a fetch that could only fail.
        $status = $garmin->dataStatus();
        if ($status->needsSignIn() || ! GarminSession::exists(ActingUser::require()->id)) {
            return Response::json([
                'started' => false,
                'reason' => $status->needsSignIn() ? $status->hint : GarminSession::notConnectedHint(),
                'sign_in_url' => route('connect.garmin'),
            ]);
        }

        // The stamp the run has to move past. Only read for the verdict at
        // the end: the stamp alone is no done-signal, see awaitFreshMirror.
        $before = $garmin->latestFetch();

        // Shares the limiter with the header button, so AI plus human
        // together can never hammer Garmin faster than one run per window,
        // for this athlete. Another athlete's window is their own. A
        // denied attempt usually means a run is already under way, which
        // the wait below reports on; the one where that run has already
        // finished is answered honestly there too.
        $started = RateLimiter::attempt(
            FetchController::limiterKey(ActingUser::require()->id), 1, fn () => true, 120
        );

        if ($started && ($reason = $fetch->start(days: self::DAYS)) !== null) {
            return Response::error(__('The fetch job could not be started: :reason', ['reason' => $reason]));
        }

        return $this->awaitFreshMirror($garmin, $fetch, $before, $started);
    }

    /**
     * Polls until the running fetch is over, then says how it ended.
     *
     * The wait lives on the server because the model on the other end cannot
     * sleep: without it, every stale-data conversation ended with "let me
     * know once the sync is through". The budget per call stays under a
     * typical connector timeout; a fetch that needs longer answers
     * still_running, and the model resumes the wait with another call
     * instead of handing the minute back to the user.
     *
     * Over means the trigger's running mark is gone, the same done-signal
     * the dashboard waits on, and not merely a moved fetch stamp: the stamp
     * moves per endpoint mid-run, the first one seconds in, while the
     * fetcher writes the activities last. Completing on the first stamp
     * told the model "the data is fresh" a minute or two before the workout
     * it was asked about had reached the mirror, which is exactly the
     * question this tool exists to settle.
     */
    private function awaitFreshMirror(GarminData $garmin, FetchTrigger $fetch, ?string $before, bool $started): Response
    {
        $calledAt = now();
        $deadline = microtime(true) + (int) config('garmin.fetch.wait_seconds');

        while (true) {
            // The run itself can be the thing that discovers the login is
            // gone; waiting the budget out would only hide that.
            $status = $garmin->dataStatus();
            if ($status->needsSignIn()) {
                return Response::json([
                    'started' => $started,
                    'completed' => false,
                    'reason' => $status->hint,
                    'sign_in_url' => route('connect.garmin'),
                ]);
            }

            // A run that died never clears its stamp backlog, so the wait
            // ends on the reason rather than sitting the budget out. Guarded
            // by age for the call that started nothing: the failure cache
            // survives a day, and yesterday's verdict is not this run's.
            $failure = $fetch->lastFailure();
            if ($failure !== null && ($started || Carbon::parse($failure['at'])->gte($calledAt))) {
                return Response::json([
                    'started' => $started,
                    'completed' => false,
                    'failed' => true,
                    'reason' => $status->reason() ?? $failure['message'],
                ]);
            }

            $latest = $garmin->latestFetch();
            $moved = $latest !== null && ($before === null || Carbon::parse($latest)->gt(Carbon::parse($before)));

            if (! $fetch->isRunning()) {
                if ($moved) {
                    return Response::json([
                        'started' => $started,
                        'completed' => true,
                        'last_fetch' => $latest,
                        'hint' => __('The fetch is done and the data is fresh. Call get-health-summary or query-health-data again now.'),
                    ]);
                }

                // Started, over, nothing written, no failure recorded:
                // Garmin had nothing newer to give. Said out loud, because
                // it answers the question the tool was called for, and the
                // reason is almost always the watch rather than the fetch.
                if ($started) {
                    return Response::json([
                        'started' => true,
                        'completed' => true,
                        'last_fetch' => $latest,
                        'hint' => __('The fetch has finished. No new data had arrived at Garmin.'),
                    ]);
                }

                // Nothing was started and nothing is running: the run that
                // closed the limiter window has already finished. Waiting
                // the budget out here answered still_running with nothing
                // running, and the model called again, forever inside the
                // window. Say when a new run can start instead.
                return Response::json([
                    'started' => false,
                    'completed' => false,
                    'not_started' => true,
                    'last_fetch' => $latest,
                    'retry_in_seconds' => RateLimiter::availableIn(FetchController::limiterKey(ActingUser::require()->id)),
                    'hint' => __('No fetch is running: the last one finished moments ago. Use the data as it is, or wait retry_in_seconds and call refresh-data again if something is still missing.'),
                ]);
            }

            if (microtime(true) >= $deadline) {
                return Response::json([
                    'started' => $started,
                    'completed' => false,
                    'still_running' => true,
                    'hint' => __('The fetch is still running. Call refresh-data again right away to keep waiting; that does not start a second run.'),
                ]);
            }

            usleep((int) (config('garmin.fetch.wait_poll_seconds') * 1_000_000));
        }
    }
}
