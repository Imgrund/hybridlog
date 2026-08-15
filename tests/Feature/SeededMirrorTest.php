<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The state the Quickstart leaves a stranger in, and the one nobody was
 * looking at: seeded from fetcher/seed_demo.py, never connected to
 * Garmin, and DEMO_MODE off, because this is somebody's own copy rather
 * than the public demo.
 *
 * Followed to the letter, the readme puts a reader here, and the header
 * used to greet them with "Garmin connected", a green dot and a fetch two
 * minutes old, over an account that does not exist. The seed writes
 * fetch_log the way fetch.py does, deliberately, so that a complete set
 * of days does not sit under a stale-data warning, and every reading of
 * "connected" was derived from that log. The demo is covered by
 * DemoModeTest, which replaces these lines outright; this is the copy
 * that has no such excuse.
 */
class SeededMirrorTest extends TestCase
{
    use RefreshDatabase;

    /**
     * What seed_demo.py leaves behind: days stamped as its own, and a
     * fetch_log written at the moment the seed ran.
     */
    private function aSeededMirror(): void
    {
        $this->seedMirror('days', [[
            'date' => now()->toDateString(),
            'steps' => 8123,
            'fetched_at' => 'demo',
        ]]);

        $this->seedMirror('fetch_log', [[
            'date' => now()->toDateString(),
            'kind' => 'stats',
            'ok' => 1,
            'fetched_at' => now()->format('Y-m-d\TH:i:s'),
        ]]);
    }

    public function test_a_seeded_installation_does_not_claim_a_garmin_connection(): void
    {
        $this->aSeededMirror();

        $this->actingAs($this->athlete())->get('/')
            ->assertOk()
            ->assertSee(__('Connect Garmin'))
            ->assertDontSee(__('Garmin connected'))
            // The tooltip that goes with the green dot, and the tell that
            // the menu is offering the wrong one of the two: "again"
            // assumes a first time that never happened.
            ->assertDontSee(__('Sign in to Garmin again'));
    }

    public function test_it_says_where_the_numbers_actually_came_from(): void
    {
        // Not merely "not connected": a reader looking at a full dashboard
        // needs to know the numbers in front of them are invented, or the
        // sign-in prompt reads as a formality over real data.
        $this->aSeededMirror();

        $this->actingAs($this->athlete())->get('/')
            ->assertOk()
            ->assertSee('come from the demo seed rather than from Garmin', false);
    }

    public function test_the_first_real_fetch_ends_it(): void
    {
        // How the state is meant to be left, and the reason isDemo() asks
        // the newest day rather than whether any seeded row exists: a real
        // fetch writes over today, the older seeded days stay where they
        // are, and nothing about this is sticky.
        $this->aSeededMirror();

        $this->seedMirror('days', [[
            'date' => now()->addDay()->toDateString(),
            'steps' => 9001,
            'fetched_at' => now()->format('Y-m-d\TH:i:s'),
        ]]);

        $this->actingAs($this->athlete())->get('/')
            ->assertOk()
            ->assertSee(__('Garmin connected'))
            ->assertDontSee('come from the demo seed', false);
    }
}
