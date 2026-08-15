<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunGarminFetch;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * How a fetch actually happens: an artisan command that runs the Python
 * fetcher, a queued job that calls it, and the schedule that starts it
 * three times a day.
 */
class FetchSchedulingTest extends TestCase
{
    // A fetch is for somebody now: the command reads the installation
    // owner where no tenant is named, so there has to be one.
    use RefreshDatabase;

    public function test_the_command_runs_the_configured_fetcher(): void
    {
        Process::fake();
        config(['garmin.fetch.command' => '/opt/venv/bin/python /app/fetcher/fetch.py']);
        $owner = $this->athlete();

        $this->artisan('garmin:fetch')->assertSuccessful();

        Process::assertRan(fn ($process) => $process->command
            === '/opt/venv/bin/python /app/fetcher/fetch.py --tenant='.$owner->id);
    }

    public function test_the_command_passes_the_fetchers_own_options_through(): void
    {
        // The backfill is how a new installation gets its history, so the
        // options have to survive the trip through artisan.
        Process::fake();
        config(['garmin.fetch.command' => 'python fetch.py']);

        $owner = $this->athlete();

        $this->artisan('garmin:fetch', ['--days' => '30', '--backfill' => '2026-05-01'])
            ->assertSuccessful();

        Process::assertRan(fn ($process) => $process->command
            === 'python fetch.py --tenant='.$owner->id.' --days=30 --backfill=2026-05-01');
    }

    public function test_the_command_rejects_an_option_it_cannot_vouch_for(): void
    {
        // The command line is handed to a shell, so anything that is not
        // plainly a date stops here rather than reaching it.
        Process::fake();
        config(['garmin.fetch.command' => 'python fetch.py']);
        $this->athlete();

        $this->artisan('garmin:fetch', ['--backfill' => '2026-05-01; rm -rf /'])->assertFailed();

        Process::assertNothingRan();
    }

    public function test_a_failing_fetcher_fails_the_command(): void
    {
        // What the queue job reads to decide whether the run is worth
        // recording as failed, and what a scheduler's log shows.
        Process::fake(['*' => Process::result(output: 'garmin login failed', exitCode: 1)]);
        $this->athlete();

        $this->artisan('garmin:fetch')->assertFailed();
    }

    public function test_the_job_runs_the_same_command(): void
    {
        Process::fake();
        config(['garmin.fetch.command' => 'python fetch.py']);

        $owner = $this->athlete();

        (new RunGarminFetch($owner->id))->handle();

        Process::assertRan(fn ($process) => $process->command === 'python fetch.py --tenant='.$owner->id);
    }

    public function test_a_failing_fetch_fails_the_job(): void
    {
        // So it lands in failed_jobs instead of being reported as done.
        Process::fake(['*' => Process::result(output: 'boom', exitCode: 2)]);
        $owner = $this->athlete();

        $this->expectException(\RuntimeException::class);

        (new RunGarminFetch($owner->id))->handle();
    }

    public function test_the_fetch_is_scheduled_at_every_configured_time(): void
    {
        config(['garmin.fetch.times' => ['06:00', '18:00']]);

        $scheduled = $this->scheduledCommands();

        $this->assertCount(7, $scheduled);
        $this->assertCount(2, array_filter($scheduled, fn ($c) => str_contains($c, 'garmin:fetch-all')));
        $this->assertCount(1, array_filter($scheduled, fn ($c) => str_contains($c, 'app:health-alerts')));
        // The companion pushes ride at fixed times after the default
        // fetches rather than following configured fetch times: their
        // hours are part of their meaning (morning, evening, Sunday).
        $this->assertCount(1, array_filter($scheduled, fn ($c) => str_contains($c, 'app:morning-briefing')));
        $this->assertCount(1, array_filter($scheduled, fn ($c) => str_contains($c, 'app:evening-nudge')));
        $this->assertCount(1, array_filter($scheduled, fn ($c) => str_contains($c, 'app:weekly-report-reminder')));
    }

    public function test_the_fetch_times_are_read_as_a_list(): void
    {
        // Written to every layer env() consults, and taken back out of
        // all of them: a developer's .env that sets the variable lands
        // in $_ENV, which outranks putenv(), and the test would quietly
        // start testing that file instead of the parsing.
        $_ENV['GARMIN_FETCH_TIMES'] = $_SERVER['GARMIN_FETCH_TIMES'] = ' 07:15 ,, 19:45 ';
        putenv('GARMIN_FETCH_TIMES= 07:15 ,, 19:45 ');

        $config = require config_path('garmin.php');

        unset($_ENV['GARMIN_FETCH_TIMES'], $_SERVER['GARMIN_FETCH_TIMES']);
        putenv('GARMIN_FETCH_TIMES');

        // Trimmed and without the empty piece a trailing comma leaves,
        // which would otherwise reach dailyAt() as an empty time.
        $this->assertSame(['07:15', '19:45'], $config['fetch']['times']);
    }

    /**
     * The commands routes/console.php schedules.
     *
     * The file registers into whatever Schedule the container holds, so it
     * is run again against a fresh one rather than read off the schedule the
     * test application happened to boot with.
     *
     * @return list<string>
     */
    private function scheduledCommands(): array
    {
        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        Facade::clearResolvedInstance(Schedule::class);

        require base_path('routes/console.php');

        return array_map(fn ($event) => (string) $event->command, $schedule->events());
    }
}
