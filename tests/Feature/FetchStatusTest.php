<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Garmin\FetchTrigger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The status endpoint the post-fetch flash polls: authenticated JSON
 * carrying the mirror's newest fetch stamp and, while a run is under
 * way, how far it has come, day by day. The poll-and-reload behaviour
 * itself is Alpine in the browser and stays a visual check.
 */
class FetchStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_status_endpoint_requires_a_login(): void
    {
        $this->get('/fetch/status')->assertRedirect(route('login'));
    }

    public function test_the_status_endpoint_returns_the_last_fetch_stamp(): void
    {
        $this->actingAs(User::factory()->create())
            ->getJson('/fetch/status')
            ->assertStatus(200)
            ->assertJsonStructure(['last_fetch']);
    }

    public function test_no_progress_is_reported_while_nothing_runs(): void
    {
        $this->actingAs($this->athlete())
            ->getJson('/fetch/status')
            ->assertJson(['running' => false, 'progress' => null]);
    }

    public function test_a_backfill_reports_its_days_as_they_are_begun(): void
    {
        // The heart of "is anything happening": three days into a
        // ninety-day first fetch, the endpoint says day 3 of 90.
        Queue::fake();
        $user = $this->athlete();
        app(FetchTrigger::class)->start($user->id, now()->subDays(89)->toDateString());

        $this->seedMirror('fetch_log', collect(range(0, 2))->map(fn (int $i) => [
            'date' => now()->subDays(89 - $i)->toDateString(),
            'kind' => 'stats',
            'ok' => 1,
            'fetched_at' => now()->format('Y-m-d\TH:i:s'),
        ])->all());

        $this->actingAs($user)
            ->getJson('/fetch/status')
            ->assertJson([
                'running' => true,
                'progress' => ['done' => 3, 'total' => 90, 'backfill' => true],
            ]);
    }

    public function test_days_of_an_earlier_run_do_not_count_as_progress(): void
    {
        // fetch_log rows are upserted per (date, kind), so the mirror is
        // full of stats rows from every run before this one. Only rows
        // this run has stamped count, or a fresh backfill would open on
        // "day 90 of 90" and never appear to move.
        Queue::fake();
        $user = $this->athlete();

        $this->seedMirror('fetch_log', [[
            'date' => now()->subDays(3)->toDateString(),
            'kind' => 'stats',
            'ok' => 1,
            'fetched_at' => now()->subHours(2)->format('Y-m-d\TH:i:s'),
        ]]);

        app(FetchTrigger::class)->start($user->id, now()->subDays(89)->toDateString());

        $this->actingAs($user)
            ->getJson('/fetch/status')
            ->assertJson(['progress' => ['done' => 0, 'total' => 90, 'backfill' => true]]);
    }

    public function test_an_ordinary_run_reports_the_week_it_walks(): void
    {
        // The fetcher's own default: --days 7, today included. The page
        // does not print this one, but the stall clock feeds on it.
        Queue::fake();
        $user = $this->athlete();
        app(FetchTrigger::class)->start($user->id);

        $this->actingAs($user)
            ->getJson('/fetch/status')
            ->assertJson(['progress' => ['done' => 0, 'total' => 7, 'backfill' => false]]);
    }

    public function test_a_running_mark_from_before_the_window_still_counts_as_running(): void
    {
        // A deploy in the middle of a run leaves the old shape in the
        // cache: a bare timestamp. That run is still a run and the page
        // keeps waiting on it, it just has no progress to print.
        $user = $this->athlete();
        Cache::put('garmin:fetch:running:'.$user->id, now()->toIso8601String(), 60);

        $this->actingAs($user)
            ->getJson('/fetch/status')
            ->assertJson(['running' => true, 'progress' => null]);
    }
}
