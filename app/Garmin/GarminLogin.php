<?php

declare(strict_types=1);

namespace App\Garmin;

use App\Jobs\RunGarminLogin;
use App\Models\GarminLoginAttempt;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Signs the installation in to Garmin, from the connection page.
 *
 * This exists because a Garmin login cannot be done in one request. If
 * the account has MFA on, Garmin only sends the code once the password
 * has been accepted, and the half-finished session that can receive that
 * code lives inside the Python client object: there is nothing to hand
 * to a second process. So one long-lived process holds the login open
 * and talks to fetcher/login.py --stdio, while the browser and this
 * class pass the code between them through garmin_login_attempts.
 *
 * The queue worker is that long-lived process. A web request cannot be:
 * it has to answer the browser while the login is still waiting.
 *
 * The password never lands anywhere it could be read back. It reaches
 * the worker inside an encrypted job payload and goes from there
 * straight into the process's stdin, so it is neither in the attempts
 * table, nor in a log line, nor in the argument list any other process
 * on the host can see.
 */
class GarminLogin
{
    /** Prefix fetcher/login.py marks its status lines with. */
    private const STATUS = '__GARMIN__';

    /**
     * The prefix fetcher/login.py gives the login library's own log.
     *
     * Only lines carrying it are passed on. Everything else a process on
     * the other side of a pipe might print is unknown by definition, and
     * this one was handed a password.
     */
    private const LIBRARY_LOG = 'garmin: ';

    /** Output read from the process but not yet split into lines. */
    private string $buffer = '';

    /** The same, for the standard error stream. */
    private string $errorBuffer = '';

    /**
     * Begins a sign-in and returns the attempt the page can watch.
     *
     * Whatever the same user had before is dropped first: only one login
     * per user can be in flight, and a stale row from last week would
     * otherwise be the one the page polls. Other users' attempts are
     * none of this one's business.
     */
    public function start(string $email, string $password, User $user): GarminLoginAttempt
    {
        GarminLoginAttempt::query()->where('user_id', $user->id)->delete();

        $attempt = GarminLoginAttempt::create(['user_id' => $user->id, 'status' => GarminLoginAttempt::STARTING]);

        RunGarminLogin::dispatch($attempt->id, $email, $password, $user->id);

        return $attempt;
    }

    /**
     * Runs the login to its end, updating the attempt as it goes.
     *
     * Called on the worker. Never throws: the whole point of the attempt
     * row is that the failure has somewhere to be shown, and an
     * exception here would put the payload, password included, into
     * failed_jobs instead.
     */
    public function run(int $attemptId, string $email, string $password, int $tenant): void
    {
        $attempt = GarminLoginAttempt::find($attemptId);

        if ($attempt === null) {
            return;
        }

        $command = trim((string) config('garmin.login.command'));

        if ($command === '') {
            $this->fail($attempt, 'No login command configured; set GARMIN_LOGIN_COMMAND.');

            return;
        }

        $input = new InputStream;
        $process = Process::fromShellCommandline(
            // The tenant decides which session row the login writes and
            // which mirror it makes sure exists, so it is not optional
            // here even though login.py defaults it: the default is the
            // first athlete, and this may be the fourth. An integer from
            // the users table, so nothing shell-shaped can reach the line.
            $command.' --stdio --tenant '.$tenant,
            base_path(),
            null,
            $input,
            (float) config('garmin.login.timeout'),
        );

        try {
            $process->start();

            // Two lines, in the order login.py reads them. The password
            // goes through the pipe and is gone; nothing keeps a copy.
            $input->write($email."\n".$password."\n");

            $status = $this->await($process, (int) config('garmin.login.step_timeout'));

            if ($status !== null && $status['status'] === 'MFA_REQUIRED') {
                $status = $this->answerMfa($attempt, $process, $input, $status['detail']);
            }

            $input->close();

            // Once more at the end: the lines that explain a login are the
            // last ones it writes, and await() stops reading the moment it
            // has its verdict.
            $this->drainLibraryLog($process);
            $this->finish($attempt, $status, $tenant);
        } catch (\Throwable $exception) {
            $this->fail($attempt, class_basename($exception).': '.$exception->getMessage());
        } finally {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
    }

    /**
     * Waits for the athlete's code, hands it over, waits for the verdict.
     *
     * $channel is what login.py learned about where Garmin is sending the
     * code. It is stored rather than interpreted here: the page turns it
     * into a sentence, and a value we do not recognise is still worth
     * having when a sign-in has to be explained afterwards.
     *
     * @return array{status: string, detail: string}|null
     */
    private function answerMfa(GarminLoginAttempt $attempt, Process $process, InputStream $input, string $channel = ''): ?array
    {
        $attempt->update([
            'status' => GarminLoginAttempt::MFA_REQUIRED,
            'mfa_channel' => $channel !== '' ? $channel : null,
        ]);

        $code = $this->awaitCode($attempt);

        if ($code === null) {
            return [
                'status' => 'FAILED',
                'detail' => __('No code arrived in time. Start the sign-in again.'),
            ];
        }

        // Cleared as it is used: the code has done its work the moment
        // the process has it, and a used code is only a liability.
        $attempt->update(['status' => GarminLoginAttempt::COMPLETING, 'mfa_code' => null]);

        $input->write($code."\n");

        return $this->await($process, (int) config('garmin.login.step_timeout'));
    }

    /**
     * Polls the attempt row until the browser has written the MFA code.
     *
     * A database poll rather than anything cleverer because the two ends
     * are in different containers, and this is the one channel both are
     * certain to have.
     */
    private function awaitCode(GarminLoginAttempt $attempt): ?string
    {
        $deadline = microtime(true) + (int) config('garmin.login.mfa_timeout');

        while (microtime(true) < $deadline) {
            $code = GarminLoginAttempt::query()->whereKey($attempt->id)->value('mfa_code');

            if (is_string($code) && $code !== '') {
                return $code;
            }

            usleep(1_000_000);
        }

        return null;
    }

    /**
     * Reads process output until a status line shows up.
     *
     * Only prefixed lines count. The Python side shares its stdout with
     * whatever the HTTP library feels like printing, and a login must
     * not be decided by a stray line of somebody else's logging.
     *
     * @return array{status: string, detail: string}|null null on timeout
     *                                                    or a process
     *                                                    that died quietly
     */
    private function await(Process $process, int $timeoutSeconds): ?array
    {
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            $this->drainLibraryLog($process);
            $this->buffer .= $process->getIncrementalOutput();

            while (($break = strpos($this->buffer, "\n")) !== false) {
                $line = trim(substr($this->buffer, 0, $break));
                $this->buffer = substr($this->buffer, $break + 1);

                if (str_starts_with($line, self::STATUS.' ')) {
                    $rest = trim(substr($line, strlen(self::STATUS) + 1));
                    [$status, $detail] = array_pad(explode(' ', $rest, 2), 2, '');

                    return ['status' => $status, 'detail' => trim($detail)];
                }
            }

            if (! $process->isRunning()) {
                // Nothing prefixed arrived and the process is gone, so
                // whatever went wrong went wrong before login.py could
                // report it. stderr is the only account left.
                $stderr = trim($process->getErrorOutput());

                return $stderr === ''
                    ? null
                    : ['status' => 'FAILED', 'detail' => mb_substr($stderr, -400)];
            }

            usleep(200_000);
        }

        return null;
    }

    /**
     * Copies the login library's own narration into the worker's log.
     *
     * The library tries five sign-in routes in turn and says which one it
     * took, and why the earlier ones did not, at debug level on standard
     * error. Nobody was reading that stream unless the process died, so
     * the one question a login that waits for a code nobody sends raises,
     * which route got here and what happened to the routes that would
     * have made Garmin send one, had no answer after the fact.
     *
     * Only the prefixed lines go through. The library's own logger is the
     * only thing fetcher/login.py turns on, deliberately: the HTTP layers
     * below it would log request headers, and one of those is the
     * password. Filtering here keeps that true even if something else on
     * that side ever starts talking.
     */
    private function drainLibraryLog(Process $process): void
    {
        $this->errorBuffer .= $process->getIncrementalErrorOutput();

        while (($break = strpos($this->errorBuffer, "\n")) !== false) {
            $line = trim(substr($this->errorBuffer, 0, $break));
            $this->errorBuffer = substr($this->errorBuffer, $break + 1);

            if (str_starts_with($line, self::LIBRARY_LOG)) {
                Log::info('garmin login: '.substr($line, strlen(self::LIBRARY_LOG)));
            }
        }
    }

    /** @param array{status: string, detail: string}|null $status */
    private function finish(GarminLoginAttempt $attempt, ?array $status, int $tenant): void
    {
        if ($status === null) {
            $this->fail($attempt, __('Garmin did not answer in time. Try again.'));

            return;
        }

        if ($status['status'] === 'OK') {
            $attempt->update([
                'status' => GarminLoginAttempt::SUCCEEDED,
                'account' => $status['detail'] !== '' ? $status['detail'] : null,
                'error' => null,
            ]);

            $this->fillTheNewMirror($tenant);

            return;
        }

        $this->fail($attempt, $status['detail'] !== '' ? $status['detail'] : __('The sign-in failed.'));
    }

    /**
     * Starts the first fetch of a newly connected athlete, with history.
     *
     * A sign-in leaves an empty schema, and an empty dashboard is what
     * the athlete sees until the next scheduled slot comes round, which
     * may be most of a day. Worse, the ordinary fetch reaches back seven
     * days, so even then the page would open on a week and the range
     * switch would offer stages the mirror cannot fill for months.
     *
     * So the first fetch is a backfill instead. It takes far longer than
     * a regular run (a few minutes for the daily summaries, more where
     * Garmin has detailed streams), which is why it is a queued job and
     * not something the sign-in waits for. The page shows a fetch under
     * way and fills in as it lands.
     *
     * Only when the mirror is empty. Signing in again, after a password
     * change or an expired session, is the common case by far, and
     * re-reading a quarter of a year for it would hammer Garmin for data
     * already sitting in the schema.
     */
    private function fillTheNewMirror(int $tenant): void
    {
        try {
            if (Mirror::forTenant($tenant)->table('days')->exists()) {
                return;
            }

            app(FetchTrigger::class)->start($tenant, now()->subDays(
                (int) config('garmin.fetch.first_connect_days')
            )->toDateString());
        } catch (\Throwable $exception) {
            // The sign-in itself worked, and that is what the athlete is
            // watching. A mirror that could not be reached from here is
            // the next scheduled fetch's problem, not a reason to show a
            // failed login.
            Log::warning('garmin login: could not start the first fetch for user '.$tenant.': '.$exception->getMessage());
        }
    }

    private function fail(GarminLoginAttempt $attempt, string $error): void
    {
        $attempt->update([
            'status' => GarminLoginAttempt::FAILED,
            'mfa_code' => null,
            'error' => mb_substr($error, 0, 500),
        ]);
    }
}
