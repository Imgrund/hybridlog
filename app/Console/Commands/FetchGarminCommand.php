<?php

namespace App\Console\Commands;

use App\Models\AthleteProfile;
use App\Models\User;
use App\Tenancy\ActingUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Runs fetcher/fetch.py and passes its output through.
 *
 * The fetcher is a Python program and stays one: it speaks to Garmin's
 * unofficial web API through python-garminconnect, which has no PHP
 * equivalent. What this command adds is a way to start it that exists
 * everywhere PHP does: the scheduler calls it and so does the queue job,
 * which between them are the only two ways the mirror ever fills. See
 * config/garmin.php for the interpreter and the timeout.
 *
 * It fetches for one athlete. Which one is --tenant, and the fetcher
 * takes it from here: their mirror schema and their Garmin session are
 * both keyed by it. Left out it is the installation owner, which is who
 * a bare `artisan garmin:fetch` has always meant; garmin:fetch-all is
 * what names everybody in turn.
 */
class FetchGarminCommand extends Command
{
    protected $signature = 'garmin:fetch
        {--tenant= : Fetch for this user id instead of the installation owner}
        {--days= : Fetch the last N days instead of the fetcher\'s default of 7}
        {--backfill= : Fetch from YYYY-MM-DD until today}';

    protected $description = 'Fetch fresh data from Garmin Connect into one athlete\'s mirror';

    public function handle(): int
    {
        $command = trim((string) config('garmin.fetch.command'));

        if ($command === '') {
            $this->error('No fetch command configured; set GARMIN_FETCH_COMMAND to the fetcher and its interpreter.');

            return self::FAILURE;
        }

        $arguments = $this->fetcherArguments();

        if ($arguments === null) {
            return self::FAILURE;
        }

        // Shell string rather than an argument list, because the command is
        // configured as one ("python3 /app/fetcher/fetch.py") and cannot be
        // split into program and arguments without guessing. The two values
        // that reach it from outside are validated above, so nothing here
        // can carry a shell metacharacter.
        $result = Process::path(base_path())
            ->timeout((int) config('garmin.fetch.timeout'))
            ->run(
                trim($command.' '.implode(' ', $arguments)),
                fn (string $type, string $output) => $this->output->write($output),
            );

        // The exit code is the whole point of the return value: the queue
        // job and the scheduler both decide from it whether the run failed,
        // and fetch.py already exits non-zero on a broken login.
        if (! $result->successful()) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The options, validated into fetcher arguments.
     *
     * Validated because the result is handed to a shell. Both are typed
     * by hand on an artisan invocation today, but a command's options are
     * exactly the kind of thing a later caller passes through from
     * somewhere less trustworthy, and the check costs two regexes.
     *
     * @return list<string>|null null when an option was malformed
     */
    private function fetcherArguments(): ?array
    {
        $tenant = $this->tenant();

        if ($tenant === null) {
            return null;
        }

        $arguments = ['--tenant='.$tenant];

        $days = (string) $this->option('days');
        if ($days !== '') {
            if (! preg_match('/^\d{1,4}$/', $days)) {
                $this->error('--days takes a whole number of days.');

                return null;
            }

            $arguments[] = '--days='.$days;
        }

        $backfill = (string) $this->option('backfill');
        if ($backfill !== '') {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $backfill)) {
                $this->error('--backfill takes a date as YYYY-MM-DD.');

                return null;
            }

            $arguments[] = '--backfill='.$backfill;
        }

        // The athlete's own sky, when they have named one. Passed on the
        // command line rather than read by the fetcher itself: the
        // fetching role deliberately has no rights in the public schema
        // where the profile lives, and handing it those to look up two
        // numbers would undo the point of the split. Formatted rather
        // than concatenated, so what reaches the shell is a number
        // whatever the column hands back.
        $profile = AthleteProfile::query()->where('user_id', $tenant)->first();

        if ($profile?->hasLocation()) {
            $arguments[] = '--lat='.sprintf('%.5f', $profile->latitude);
            $arguments[] = '--lon='.sprintf('%.5f', $profile->longitude);
        }

        return $arguments;
    }

    /**
     * Whose mirror this run fills.
     *
     * A user id rather than a name, because that is what the schema and
     * the session row are keyed by, and it is checked against the users
     * table rather than merely parsed: a typo would otherwise create a
     * mirror for an athlete who does not exist and fill it with the
     * session of one who does, since the fetcher provisions what it is
     * pointed at.
     */
    private function tenant(): ?int
    {
        $option = (string) $this->option('tenant');

        if ($option === '') {
            $owner = ActingUser::get();

            if ($owner === null) {
                $this->error('This installation has no account yet; create one with app:create-user --admin.');

                return null;
            }

            return $owner->id;
        }

        if (! preg_match('/^\d{1,9}$/', $option)) {
            $this->error('--tenant takes a user id.');

            return null;
        }

        if (! User::query()->whereKey((int) $option)->exists()) {
            $this->error('No account with id '.$option.'; nobody to fetch for.');

            return null;
        }

        return (int) $option;
    }
}
