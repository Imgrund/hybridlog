<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Demo\DemoMode;
use App\Garmin\GarminLogin;
use App\Models\GarminLoginAttempt;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Holds a Garmin sign-in open on a worker until it is done.
 *
 * The job exists for its lifetime, not its work: Garmin's MFA needs one
 * process to survive both halves of the login, and a web request cannot
 * be that process. See App\Garmin\GarminLogin.
 *
 * The tenant travels with it for the same reason the fetch job carries
 * one: the session this login stores is keyed by the athlete it belongs
 * to, and a worker has no request to work that out from.
 */
class RunGarminLogin implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    /**
     * One attempt, no retries.
     *
     * A retry would replay a password against an account that locks, and
     * an MFA code that Garmin has already spent. Whatever went wrong is
     * on the attempt row for the athlete to read and decide about.
     */
    public int $tries = 1;

    public int $timeout;

    /**
     * The password is a constructor argument, so it lives in the payload
     * rather than anywhere durable. ShouldBeEncrypted is what makes that
     * acceptable: the row in the jobs table is ciphertext, it is deleted
     * the moment the job finishes, and handle() below makes sure no
     * failure can copy it into failed_jobs.
     */
    public function __construct(
        public int $attemptId,
        public string $email,
        public string $password,
        public int $tenant,
    ) {
        // Room for the whole login, the wait for the code included, plus
        // a margin so the worker is never the one that gives up first.
        $this->timeout = (int) config('garmin.login.timeout') + 60;
    }

    public function handle(GarminLogin $login): void
    {
        // The queue is the last door into Garmin once the page in front of
        // it is closed (App\Http\Middleware\EnsureNotDemo), and a job
        // dispatched before the demo switch was thrown would still walk
        // through it with somebody's real password. Failed rather than
        // dropped: the attempt row is what a page watches, and one left in
        // "starting" would be watched until it timed out.
        if (DemoMode::enabled()) {
            GarminLoginAttempt::query()->whereKey($this->attemptId)->update([
                'status' => GarminLoginAttempt::FAILED,
                'error' => DemoMode::refusal(),
            ]);

            return;
        }

        // Deliberately swallows everything. A throw here would hand the
        // payload, password and all, to failed_jobs, where it would sit
        // in plain sight of anyone reading the table. GarminLogin::run()
        // records failures on the attempt row instead, which is both
        // where the athlete looks and where nothing secret is kept.
        try {
            $login->run($this->attemptId, $this->email, $this->password, $this->tenant);
        } catch (\Throwable) {
            // Already recorded by run(); nothing left to do here.
        }
    }
}
