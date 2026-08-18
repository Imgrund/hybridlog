<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Garmin\FetchTrigger;
use App\Garmin\GarminLogin;
use App\Garmin\Mirror;
use App\Http\Controllers\FetchController;
use App\Jobs\RunGarminFetch;
use App\Models\GarminLoginAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The promise the writing half has to keep: everything around the
 * mirror knows whose mirror it is.
 *
 * Each athlete has a schema and every read finds the right one. What
 * used to act for the installation instead was the writing half (the
 * scheduler, the refresh button, the queue job, the Garmin sign-in) and
 * an installation is not who a watch belongs to. These tests pin the four
 * places where that mattered.
 */
class FetchTenancyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_command_fetches_for_the_athlete_it_is_given(): void
    {
        Process::fake();
        config(['garmin.fetch.command' => 'python fetch.py']);

        $this->athlete();
        $invited = User::factory()->create();

        $this->artisan('garmin:fetch', ['--tenant' => (string) $invited->id])->assertSuccessful();

        // The fetcher is told the tenant and works the rest out from it:
        // the schema to write and the session row to sign in with are
        // both keyed by this number (fetcher/fetch.py).
        Process::assertRan(fn ($process) => $process->command === 'python fetch.py --tenant='.$invited->id);
    }

    public function test_it_refuses_a_tenant_that_is_not_an_account(): void
    {
        // Not pedantry: the fetcher provisions whatever it is pointed at,
        // so a typo would build a mirror for an athlete who does not
        // exist and fill it with the session of one who does.
        Process::fake();
        $this->athlete();

        $this->artisan('garmin:fetch', ['--tenant' => '4242'])
            ->expectsOutputToContain('No account with id 4242')
            ->assertFailed();

        Process::assertNothingRan();
    }

    public function test_the_scheduled_run_reaches_every_connected_athlete(): void
    {
        Process::fake();
        config(['garmin.fetch.command' => 'python fetch.py']);

        $owner = $this->athlete();
        $invited = User::factory()->create();
        $neverConnected = User::factory()->create();

        $this->connectGarmin($owner);
        $this->connectGarmin($invited);

        $this->artisan('garmin:fetch-all')->assertSuccessful();

        Process::assertRan(fn ($p) => $p->command === 'python fetch.py --tenant='.$owner->id);
        Process::assertRan(fn ($p) => $p->command === 'python fetch.py --tenant='.$invited->id);

        // An account without a Garmin session has nothing to fetch with.
        // Trying anyway would cost a process launch and a failed login
        // three times a day, and read as a failure in the log.
        Process::assertDidntRun(fn ($p) => $p->command === 'python fetch.py --tenant='.$neverConnected->id);
        Process::assertRanTimes(fn () => true, 2);
    }

    public function test_one_athletes_broken_session_does_not_stop_the_others(): void
    {
        $owner = $this->athlete();
        $invited = User::factory()->create();
        $this->connectGarmin($owner);
        $this->connectGarmin($invited);

        config(['garmin.fetch.command' => 'python fetch.py']);
        Process::fake([
            '*--tenant='.$owner->id => Process::result(output: 'garmin login failed', exitCode: 1),
            '*' => Process::result(output: 'ok'),
        ]);

        // A dead Garmin session is personal (they signed out, they
        // changed their password) and the next athlete's watch has
        // nothing to do with it. So the run goes on and says what broke.
        $this->artisan('garmin:fetch-all')
            ->expectsOutputToContain('Fetch failed for 1 of 2: user '.$owner->id)
            ->assertFailed();

        Process::assertRan(fn ($p) => $p->command === 'python fetch.py --tenant='.$invited->id);
    }

    public function test_an_installation_nobody_has_connected_is_not_a_failure(): void
    {
        Process::fake();
        $this->athlete();

        $this->artisan('garmin:fetch-all')
            ->expectsOutputToContain('No athlete has connected a Garmin account yet')
            ->assertSuccessful();

        Process::assertNothingRan();
    }

    public function test_a_running_fetch_belongs_to_one_athlete(): void
    {
        // Before this, both marks were a single key each. One athlete's
        // fetch told every other athlete's page that a run was under way,
        // and the spinner they got was for somebody else's watch.
        Queue::fake();
        $owner = $this->athlete();
        $invited = User::factory()->create();
        $this->connectGarmin($owner);

        $trigger = app(FetchTrigger::class);
        $trigger->start($owner->id);

        $this->assertTrue($trigger->isRunning($owner->id));
        $this->assertFalse($trigger->isRunning($invited->id));

        $this->actingAs($invited)->get('/fetch/status')->assertJson(['running' => false]);
        $this->actingAs($owner)->get('/fetch/status')->assertJson(['running' => true]);
    }

    public function test_a_failed_fetch_is_reported_to_the_athlete_it_failed_for(): void
    {
        Queue::fake();
        $owner = $this->athlete();
        $invited = User::factory()->create();

        (new RunGarminFetch($owner->id))->failed(new \RuntimeException('garmin:fetch exited with code 1'));

        $this->assertSame('garmin:fetch exited with code 1', app(FetchTrigger::class)->lastFailure($owner->id)['message']);
        $this->assertNull(app(FetchTrigger::class)->lastFailure($invited->id));
    }

    public function test_the_two_minute_window_is_each_athletes_own(): void
    {
        // Shared, the first person to press the button would have locked
        // everybody else out of their own watch for two minutes.
        Process::fake();
        Queue::fake();
        $owner = $this->athlete();
        $invited = User::factory()->create();
        $this->connectGarmin($owner);
        $this->connectGarmin($invited);

        RateLimiter::clear(FetchController::limiterKey($owner->id));
        RateLimiter::clear(FetchController::limiterKey($invited->id));

        $this->actingAs($owner)->post('/fetch')->assertSessionHas('fetch_started');
        $this->actingAs($owner)->post('/fetch')->assertSessionHas('fetch_busy');
        $this->actingAs($invited)->post('/fetch')->assertSessionHas('fetch_started');
    }

    public function test_the_sign_in_stores_the_session_under_the_athlete_signing_in(): void
    {
        // login.py writes garmin_private.garmin_session keyed by tenant,
        // so a login that forgot to name one would overwrite the first
        // athlete's Garmin session with the second athlete's tokens.
        $invited = User::factory()->create();
        $attempt = GarminLoginAttempt::create([
            'user_id' => $invited->id,
            'status' => GarminLoginAttempt::STARTING,
        ]);

        // Echoes the command line it was given back as the account name,
        // which is the one place a status line carries free text.
        config(['garmin.login.command' => 'sh -c \'printf "__GARMIN__ OK tenant=$3\\n"\' --']);

        app(GarminLogin::class)->run($attempt->id, 'athlete@example.com', 'secret', $invited->id);

        $this->assertSame(GarminLoginAttempt::SUCCEEDED, $attempt->refresh()->status);
        $this->assertSame('tenant='.$invited->id, $attempt->account);
    }

    public function test_a_first_sign_in_starts_a_backfill_and_a_later_one_does_not(): void
    {
        Queue::fake();
        config(['garmin.login.command' => 'sh -c \'printf "__GARMIN__ OK Athlete\\n"\'']);

        $invited = User::factory()->create();
        // What the real login.py leaves behind before it reports OK, and
        // what the trigger checks before it starts the backfill.
        $this->connectGarmin($invited);
        $attempt = fn () => GarminLoginAttempt::create([
            'user_id' => $invited->id,
            'status' => GarminLoginAttempt::STARTING,
        ]);

        // An empty mirror means a dashboard with nothing on it until the
        // next scheduled slot, which may be most of a day away, and then
        // only a week of history for a page that opens on ninety days.
        app(GarminLogin::class)->run($attempt()->id, 'athlete@example.com', 'secret', $invited->id);

        Queue::assertPushed(RunGarminFetch::class, fn ($job) => $job->tenant === $invited->id
            && $job->backfill === now()->subDays(90)->toDateString());

        // Signing in again (an expired session, a changed password) is
        // the common case, and re-reading a quarter of a year for it
        // would ask Garmin for data already sitting in the schema.
        $this->seedMirror('days', [[
            'date' => now()->subDay()->toDateString(),
            'steps' => 4242,
            'fetched_at' => now()->format('Y-m-d\TH:i:s'),
        ]], $invited);

        Queue::fake();
        app(GarminLogin::class)->run($attempt()->id, 'athlete@example.com', 'secret', $invited->id);

        Queue::assertNothingPushed();
    }
}
