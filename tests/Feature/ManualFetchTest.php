<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Garmin\FetchTrigger;
use App\Http\Controllers\FetchController;
use App\Jobs\RunGarminFetch;
use App\Mcp\Servers\GarminHealthServer;
use App\Mcp\Tools\RefreshDataTool;
use App\Models\ConnectorSettings;
use App\Models\McpToolCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ManualFetchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Every test here is one athlete pressing their own button, and
        // the limiter window is theirs alone now, so it is theirs that
        // has to be cleared between tests.
        RateLimiter::clear(FetchController::limiterKey($this->athlete()->id));
    }

    public function test_the_button_dispatches_a_fetch_job(): void
    {
        // Nothing is executed inside the request: a worker spends the minute
        // the fetch takes, while the page gets its "started" flash at once.
        Process::fake();
        Queue::fake();

        $this->actingAs($this->athlete())
            ->post('/fetch')
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('fetch_started');

        Queue::assertPushed(RunGarminFetch::class);
        Process::assertNothingRan();
    }

    public function test_a_second_click_within_the_window_does_not_start_a_second_fetch(): void
    {
        // The limiter sits in front of the trigger, so the second click never
        // reaches the queue at all.
        Process::fake();
        Queue::fake();
        $user = $this->athlete();

        $this->actingAs($user)->post('/fetch')->assertSessionHas('fetch_started');
        $this->actingAs($user)->post('/fetch')->assertSessionHas('fetch_busy');

        Queue::assertPushed(RunGarminFetch::class, 1);
    }

    public function test_fetching_requires_a_login(): void
    {
        Process::fake();

        $this->post('/fetch')->assertRedirect(route('login'));

        Process::assertNothingRan();
    }

    public function test_the_mcp_refresh_tool_dispatches_the_same_job(): void
    {
        // The button and the tool are two doors to one fetch, so a change to
        // how fetches are started can never leave the model on an older path.
        Process::fake();
        Queue::fake();

        GarminHealthServer::tool(RefreshDataTool::class, []);

        Queue::assertPushed(RunGarminFetch::class);
        $this->assertTrue(
            McpToolCall::where('tool', 'refresh-data-tool')->where('ok', true)->exists()
        );
    }

    public function test_the_mcp_refresh_tool_obeys_its_permission_toggle(): void
    {
        // It is the only tool that starts work outside the request, so a user
        // who switched everything off must not be able to trigger a sync.
        Queue::fake();
        ConnectorSettings::for($this->athlete())->update(['allow_refresh' => false]);

        GarminHealthServer::tool(RefreshDataTool::class, []);

        Queue::assertNothingPushed();
        $this->assertFalse(McpToolCall::where('tool', 'refresh-data-tool')->sole()->ok);
    }

    public function test_the_connect_page_offers_the_refresh_toggle(): void
    {
        $this->actingAs($this->athlete())
            ->get('/connect')
            ->assertStatus(200)
            ->assertSee('Start a fetch');
    }

    public function test_the_refresh_button_names_garmin_as_the_source(): void
    {
        $this->actingAs($this->athlete())
            ->get('/')
            ->assertStatus(200)
            ->assertSee('Fetch from Garmin');
    }

    public function test_the_fetch_timestamp_is_the_control_that_reloads_the_view(): void
    {
        // The answer to "is there anything new": re-read the mirror. It
        // hangs off the line that says how old the view is, which is the
        // only place a reader looks for that, and it is a plain link so it
        // works before Alpine and keeps the range being read.
        $this->actingAs($this->athlete())
            ->get('/?range=30')
            ->assertStatus(200)
            ->assertSee('Reload the view to see whether new data has arrived.')
            ->assertSee('href="http://localhost/?range=30"', false);
    }

    public function test_the_page_reports_a_fetch_it_did_not_start_itself(): void
    {
        // The scheduled run, and a fetch started from the phone, are just
        // as much the reason a number is still yesterday's. Before this,
        // only the request that started a fetch knew one was under way.
        Queue::fake();
        app(FetchTrigger::class)->start($this->athlete()->id);

        $this->actingAs($this->athlete())
            ->get('/')
            ->assertStatus(200)
            ->assertSee('A fetch from Garmin is running. The page reloads by itself once the data is in.');
    }

    public function test_the_page_stays_quiet_when_no_fetch_is_running(): void
    {
        $this->actingAs($this->athlete())
            ->get('/')
            ->assertStatus(200)
            ->assertDontSee('A fetch from Garmin is running. The page reloads by itself once the data is in.');
    }

    public function test_the_status_endpoint_reports_whether_a_fetch_is_under_way(): void
    {
        // What lets the page tell "still working" from "done, nothing new":
        // without it a fetch that brought nothing looked identical to one
        // that was still running, until the four-minute cap ran out.
        Queue::fake();
        $user = $this->athlete();

        $this->actingAs($user)->get('/fetch/status')->assertJson(['running' => false]);

        app(FetchTrigger::class)->start($this->athlete()->id);
        $this->actingAs($user)->get('/fetch/status')->assertJson(['running' => true]);

        app(FetchTrigger::class)->finish($this->athlete()->id);
        $this->actingAs($user)->get('/fetch/status')->assertJson(['running' => false]);
    }

    public function test_a_failed_run_stops_claiming_to_be_running(): void
    {
        // The failure path clears the mark as well, so a page waiting on a
        // run that died is not left watching a spinner for its full TTL.
        Queue::fake();
        $trigger = app(FetchTrigger::class);
        $trigger->start($this->athlete()->id);

        (new RunGarminFetch($this->athlete()->id))->failed(new \RuntimeException('garmin:fetch exited with code 1'));

        $this->assertFalse($trigger->isRunning($this->athlete()->id));
        $this->assertSame('garmin:fetch exited with code 1', $trigger->lastFailure($this->athlete()->id)['message']);
    }

    public function test_the_body_map_shows_the_metabolism_system(): void
    {
        $this->actingAs($this->athlete())
            ->get('/')
            ->assertStatus(200)
            ->assertSee('Metabolism');
    }

    public function test_the_page_announces_a_running_backfill_with_its_size(): void
    {
        // The first fetch spends many minutes on ninety days, and the
        // page has to say so from its first paint, before the poll has
        // ever answered, or the wait opens on a line about "about a
        // minute" that then quietly stops being true.
        Queue::fake();
        $user = $this->athlete();
        app(FetchTrigger::class)->start($user->id, now()->subDays(89)->toDateString());

        $this->actingAs($user)
            ->get('/')
            ->assertStatus(200)
            ->assertSee('First fetch: getting the last 90 days from Garmin.');
    }

    public function test_the_refresh_tool_waits_and_reports_the_finished_fetch(): void
    {
        // The whole point of the wait: the model gets "completed" back and
        // can re-query in the same turn, instead of ending the conversation
        // with "sag Bescheid, wenn der Sync durch ist".
        $this->seedMirror('fetch_log', [[
            'date' => now()->subDay()->toDateString(),
            'kind' => 'daily',
            'ok' => 1,
            'fetched_at' => now()->subHours(2)->format('Y-m-d\TH:i:s'),
        ]]);

        // Stands in for the worker: by the first poll the run has written
        // its success row and is already over, the shortest fetch the wait
        // loop can observe.
        $this->mock(FetchTrigger::class, function ($mock) {
            $mock->shouldReceive('start')->once()->andReturnUsing(function () {
                // Through seedMirror, which writes as the role that owns
                // the mirror. Inside a tool call the connection is switched
                // into the tenant's reader, and a reader may not insert.
                $this->seedMirror('fetch_log', [[
                    'date' => now()->toDateString(),
                    'kind' => 'daily',
                    'ok' => 1,
                    'fetched_at' => now()->format('Y-m-d\TH:i:s'),
                ]]);

                return null;
            });
            $mock->shouldReceive('isRunning')->andReturnFalse();
            $mock->shouldReceive('lastFailure')->andReturnNull();
        });

        $response = GarminHealthServer::tool(RefreshDataTool::class, []);

        $response->assertOk()->assertSee('"completed":true');
    }

    public function test_the_refresh_tool_does_not_call_a_moving_stamp_done(): void
    {
        // The regression this suite must never readmit: the fetch stamp
        // moves per endpoint mid-run, the first one seconds in, while the
        // fetcher writes the activities last. Completing on the first
        // stamp told the model "the data is fresh" a minute or two before
        // the workout it was asked about had reached the mirror.
        $this->seedMirror('fetch_log', [[
            'date' => now()->subDay()->toDateString(),
            'kind' => 'daily',
            'ok' => 1,
            'fetched_at' => now()->subHours(2)->format('Y-m-d\TH:i:s'),
        ]]);

        $this->mock(FetchTrigger::class, function ($mock) {
            $mock->shouldReceive('start')->once()->andReturnUsing(function () {
                // The run's first endpoints have landed...
                $this->seedMirror('fetch_log', [[
                    'date' => now()->toDateString(),
                    'kind' => 'stats',
                    'ok' => 1,
                    'fetched_at' => now()->format('Y-m-d\TH:i:s'),
                ]]);

                return null;
            });
            // ...but the run itself is still on its way to the activities.
            $mock->shouldReceive('isRunning')->andReturnTrue();
            $mock->shouldReceive('lastFailure')->andReturnNull();
        });

        $response = GarminHealthServer::tool(RefreshDataTool::class, []);

        $response->assertOk()->assertSee('"completed":false')->assertSee('"still_running":true');
    }

    public function test_the_refresh_tool_reports_a_run_that_brought_nothing_new(): void
    {
        // Over with the stamp unmoved means Garmin had nothing newer to
        // give, which is the answer to the question the tool was called
        // for, and the reason is almost always the watch, not the fetch.
        $this->seedMirror('fetch_log', [[
            'date' => now()->subDay()->toDateString(),
            'kind' => 'daily',
            'ok' => 1,
            'fetched_at' => now()->subHours(2)->format('Y-m-d\TH:i:s'),
        ]]);

        $this->mock(FetchTrigger::class, function ($mock) {
            $mock->shouldReceive('start')->once()->andReturnNull();
            $mock->shouldReceive('isRunning')->andReturnFalse();
            $mock->shouldReceive('lastFailure')->andReturnNull();
        });

        $response = GarminHealthServer::tool(RefreshDataTool::class, []);

        $response->assertOk()->assertSee('"completed":true')->assertSee('No new data had arrived at Garmin');
    }

    public function test_the_refresh_tool_is_honest_inside_a_closed_window_with_nothing_running(): void
    {
        // The window is per start, not per run: a fetch started ninety
        // seconds ago is long finished. The tool used to wait its budget
        // out here and answer still_running with nothing running, and the
        // model called again, forever inside the window.
        $this->seedMirror('fetch_log', [[
            'date' => now()->toDateString(),
            'kind' => 'daily',
            'ok' => 1,
            'fetched_at' => now()->subMinute()->format('Y-m-d\TH:i:s'),
        ]]);

        RateLimiter::attempt(FetchController::limiterKey($this->athlete()->id), 1, fn () => true, 120);

        $this->mock(FetchTrigger::class, function ($mock) {
            $mock->shouldReceive('start')->never();
            $mock->shouldReceive('isRunning')->andReturnFalse();
            $mock->shouldReceive('lastFailure')->andReturnNull();
        });

        $response = GarminHealthServer::tool(RefreshDataTool::class, []);

        $response->assertOk()
            ->assertSee('"not_started":true')
            ->assertSee('retry_in_seconds');
    }

    public function test_the_refresh_tool_surfaces_the_run_that_died(): void
    {
        // A run that died of a timeout or a lost database never moves the
        // stamp; without this the tool sat its budget out and reported
        // still_running about a run that was already in failed_jobs.
        $this->seedMirror('fetch_log', [[
            'date' => now()->subDay()->toDateString(),
            'kind' => 'daily',
            'ok' => 1,
            'fetched_at' => now()->subHours(2)->format('Y-m-d\TH:i:s'),
        ]]);

        $this->mock(FetchTrigger::class, function ($mock) {
            $mock->shouldReceive('start')->once()->andReturnNull();
            $mock->shouldReceive('lastFailure')->andReturn([
                'at' => now()->toIso8601String(),
                'message' => 'garmin:fetch exited with code 1',
            ]);
        });

        $response = GarminHealthServer::tool(RefreshDataTool::class, []);

        $response->assertOk()
            ->assertSee('"failed":true')
            ->assertSee('garmin:fetch exited with code 1');
    }

    public function test_a_call_while_a_fetch_runs_waits_instead_of_refusing(): void
    {
        // The second call used to be turned away by the rate limiter. Now it
        // is the way the model resumes waiting, and it must not queue a
        // second fetch while doing so.
        Queue::fake();

        GarminHealthServer::tool(RefreshDataTool::class, []);
        $response = GarminHealthServer::tool(RefreshDataTool::class, []);

        $response->assertOk()->assertSee('"still_running":true');
        Queue::assertPushed(RunGarminFetch::class, 1);
    }

    public function test_a_login_failure_during_the_run_ends_the_wait_with_the_reason(): void
    {
        // A run that discovers the Garmin login is gone must surface that,
        // not wait the budget out and report "still running".
        $this->seedMirror('fetch_log', [[
            'date' => now()->subDay()->toDateString(),
            'kind' => 'daily',
            'ok' => 1,
            'fetched_at' => now()->subHours(2)->format('Y-m-d\TH:i:s'),
        ]]);

        $this->mock(FetchTrigger::class, function ($mock) {
            $mock->shouldReceive('start')->once()->andReturnUsing(function () {
                $this->seedMirror('fetch_log', [[
                    'date' => now()->toDateString(),
                    'kind' => 'login',
                    'ok' => 0,
                    'error' => 'Garmin MFA required',
                    'fetched_at' => now()->format('Y-m-d\TH:i:s'),
                ]]);

                return null;
            });
        });

        $response = GarminHealthServer::tool(RefreshDataTool::class, []);

        $response->assertOk()->assertSee('The Garmin login has expired');
    }
}
