<?php

namespace App\Jobs;

use App\Demo\DemoMode;
use App\Garmin\FetchTrigger;
use App\Garmin\GarminSession;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Runs garmin:fetch on a queue worker, for one athlete.
 *
 * This is what the refresh button becomes: the request dispatches and
 * returns, and a worker spends the minute the fetch takes. The dashboard
 * polls /fetch/status for the new fetch_log stamp, so the front end never
 * waits on a fetch either.
 *
 * The tenant is carried rather than resolved. A queue worker has no
 * request and no session, so asking who is acting would answer "the
 * installation owner" and quietly fill the wrong athlete's mirror.
 */
class RunGarminFetch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * A failed fetch is not retried.
     *
     * The two ways it fails are a broken Garmin login, where every retry
     * is another failed login attempt against an account that locks, and
     * a dead endpoint, which fetch.py already survives per endpoint
     * without failing the run. Either way the failure is in fetch_log,
     * where the dashboard's data_status picks it up.
     */
    public int $tries = 1;

    /**
     * Declared rather than assigned onto the object: no trait in the
     * Queueable stack carries it, and PHP 8.2 deprecates writing an
     * undeclared property.
     */
    public int $timeout;

    /**
     * @param  int  $tenant  the user whose mirror this run fills
     * @param  string|null  $backfill  a YYYY-MM-DD start date for the first
     *                                 fetch of a newly connected athlete
     */
    public function __construct(public int $tenant, public ?string $backfill = null)
    {
        // Longer than the process it starts, so a fetcher that hangs is
        // killed by its own timeout with its output in the log, rather
        // than by the worker with nothing to show.
        $this->timeout = (int) config('garmin.fetch.timeout') + 60;
    }

    /**
     * One running fetch per athlete, not one per installation.
     *
     * Unique because two fetchers writing the same days concurrently is
     * pointless at best: the schedule and a manual refresh can otherwise
     * land in the same minute. Scoped to the tenant because two athletes
     * write different schemas and have no reason to wait for each other;
     * what keeps Garmin from seeing them at once is the scheduler running
     * them in turn, not this lock.
     */
    public function uniqueId(): string
    {
        return (string) $this->tenant;
    }

    /**
     * How long the uniqueness lock is held if the job never reports back.
     */
    public function uniqueFor(): int
    {
        return $this->timeout;
    }

    public function handle(): void
    {
        // A demo has no Garmin session to fetch with, so the run would be a
        // process launch and a failed login, and its failure would sit on
        // the dashboard for the next visitor to puzzle over. The trigger
        // already refuses to dispatch there; this covers the job that was
        // queued before the switch was thrown.
        if (DemoMode::enabled()) {
            app(FetchTrigger::class)->finish($this->tenant);

            return;
        }

        // The queue outlives the state a job was dispatched in: the
        // athlete can sign out of Garmin between dispatch and pickup, and
        // a job queued before the trigger learned to refuse still has to
        // land somewhere. Same refusal as the trigger's, by name: a
        // missing session is the absence of a precondition, not news an
        // operator needs a failed_jobs row and a stacktrace to hear.
        if (! GarminSession::exists($this->tenant)) {
            $trigger = app(FetchTrigger::class);
            $trigger->recordFailure(GarminSession::notConnectedHint(), $this->tenant);
            $trigger->finish($this->tenant);

            return;
        }

        try {
            // Through artisan rather than repeating the Process call, so the
            // command stays the single description of how the fetcher runs.
            // A non-zero exit fails the job, which is what puts it in
            // failed_jobs where an operator can see it.
            $exitCode = Artisan::call('garmin:fetch', array_filter([
                '--tenant' => (string) $this->tenant,
                '--backfill' => $this->backfill,
            ]));

            if ($exitCode !== 0) {
                throw new \RuntimeException('garmin:fetch exited with code '.$exitCode.': '.trim(Artisan::output()));
            }
        } finally {
            // Cleared on the way out either way. The failure path clears it
            // again in failed(), which costs nothing and covers the run that
            // never reaches this line at all.
            app(FetchTrigger::class)->finish($this->tenant);
        }
    }

    /**
     * Leave the failure where the page that started it can find it.
     *
     * failed_jobs already holds it, but only for whoever reads the
     * database. The person who pressed the button gets nothing from that:
     * the page polls for a fetch stamp that a failed run never writes, so
     * it used to wait out its four minutes and then blame the speed.
     */
    public function failed(?Throwable $exception): void
    {
        $trigger = app(FetchTrigger::class);
        $trigger->recordFailure($exception?->getMessage() ?? '', $this->tenant);
        // Also here, and not only in handle()'s finally: a job that never
        // got that far, because the worker timed it out or the queue gave
        // up on it, would otherwise leave the page waiting on a run that
        // is already in failed_jobs.
        $trigger->finish($this->tenant);
    }
}
