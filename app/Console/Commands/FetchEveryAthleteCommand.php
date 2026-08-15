<?php

namespace App\Console\Commands;

use App\Garmin\Mirror;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Fetches for every athlete who has signed in to Garmin, one after another.
 *
 * This is what the scheduler runs. Until the mirror became per athlete
 * there was nothing to iterate and the three daily slots called
 * garmin:fetch outright; now each slot means "everybody", and everybody
 * is however many accounts the installation has.
 *
 * Sequential, deliberately. Garmin rate-limits per account and, from a
 * single deployment, per source address: two fetchers running at once
 * would be the same watch API seeing double the traffic from one place,
 * and the failure mode is a locked account rather than a slow one. Doing
 * them in turn also needs no guess at how long a fetch takes, which is
 * what an offset per tenant would have been.
 *
 * One athlete's failure does not end the run. A broken Garmin session is
 * personal (they signed out, their password changed), and the next
 * athlete's watch data has nothing to do with it, so the failure is
 * reported and the loop goes on. The exit code carries whether anything
 * failed, because the scheduler's log is where an operator looks.
 */
class FetchEveryAthleteCommand extends Command
{
    protected $signature = 'garmin:fetch-all
        {--days= : Fetch the last N days instead of the fetcher\'s default of 7}';

    protected $description = 'Fetch fresh Garmin data for every athlete who has connected an account';

    public function handle(): int
    {
        $tenants = $this->connectedTenants();

        if ($tenants === []) {
            // Not a failure. A fresh installation has nobody signed in to
            // Garmin yet, and three log lines a day saying so is the
            // honest report of that state.
            $this->info('No athlete has connected a Garmin account yet; nothing to fetch.');

            return self::SUCCESS;
        }

        $failed = [];

        foreach ($tenants as $tenant) {
            $this->info('Fetching for user '.$tenant.'.');

            try {
                $exitCode = $this->call('garmin:fetch', array_filter([
                    '--tenant' => (string) $tenant,
                    '--days' => $this->option('days'),
                ]));
            } catch (Throwable $exception) {
                $exitCode = self::FAILURE;
                $this->error('User '.$tenant.': '.$exception->getMessage());
            }

            if ($exitCode !== self::SUCCESS) {
                $failed[] = $tenant;
            }
        }

        if ($failed !== []) {
            $this->error('Fetch failed for '.count($failed).' of '.count($tenants).': user '.implode(', ', $failed).'.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The athletes with a stored Garmin session, oldest account first.
     *
     * A session is what makes a fetch possible at all, so the list comes
     * from there rather than from the users table: an account that has
     * never connected would otherwise cost a process launch and a failed
     * login three times a day, and count as a failure in the log.
     *
     * Intersected with the users table all the same. A session row
     * outlives the account it belonged to (deleting a user does not
     * reach into the mirror), and fetching for an id nobody holds would
     * quietly rebuild a deleted athlete's schema.
     *
     * @return list<int>
     */
    private function connectedTenants(): array
    {
        // The session table is one schema over from every mirror and no
        // tenant's reader may see it, so this reads as the login role.
        Mirror::unpin();

        $sessions = DB::connection('garmin')
            ->table('garmin_private.garmin_session')
            ->pluck('id')
            ->map(intval(...))
            ->all();

        if ($sessions === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $sessions)
            ->orderBy('id')
            ->pluck('id')
            ->map(intval(...))
            ->all();
    }
}
