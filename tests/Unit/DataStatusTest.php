<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Garmin\DataStatus;
use Carbon\Carbon;
use Tests\TestCase;

class DataStatusTest extends TestCase
{
    private function berlin(string $time): Carbon
    {
        return Carbon::parse($time, 'Europe/Berlin');
    }

    private function authFailure(string $fetchedAt, string $error = 'GarminConnectAuthenticationError: 401'): object
    {
        return (object) ['fetched_at' => $fetchedAt, 'error' => $error];
    }

    public function test_recent_fetch_and_synced_watch_read_as_fresh(): void
    {
        $status = DataStatus::evaluate(
            '2026-07-27T11:30:00',
            $this->berlin('2026-07-27 11:00'),
            null,
            $this->berlin('2026-07-27 12:00'),
        );

        $this->assertSame('fresh', $status->state);
        $this->assertNull($status->hint);
    }

    public function test_an_unsynced_watch_points_to_the_connect_app(): void
    {
        $status = DataStatus::evaluate(
            '2026-07-27T11:30:00',
            $this->berlin('2026-07-27 06:00'),
            null,
            $this->berlin('2026-07-27 12:00'),
        );

        $this->assertSame('watch_stale', $status->state);
        $this->assertStringContainsString('Garmin Connect app', $status->hint);
    }

    public function test_a_dead_fetch_job_outranks_the_watch(): void
    {
        $status = DataStatus::evaluate(
            '2026-07-26T13:00:00',
            $this->berlin('2026-07-27 06:00'),
            null,
            $this->berlin('2026-07-27 12:00'),
        );

        $this->assertSame('fetch_stale', $status->state);
        $this->assertStringContainsString('out of date', $status->hint);
    }

    public function test_a_mirror_that_never_fetched_reads_as_fetch_stale(): void
    {
        $status = DataStatus::evaluate(null, null, null, $this->berlin('2026-07-27 12:00'));

        $this->assertSame('fetch_stale', $status->state);
    }

    public function test_a_broken_login_outranks_everything_and_names_the_fix(): void
    {
        $status = DataStatus::evaluate(
            '2026-07-27T11:30:00',
            $this->berlin('2026-07-27 11:00'),
            $this->authFailure('2026-07-27T11:45:00'),
            $this->berlin('2026-07-27 12:00'),
        );

        $this->assertSame('auth_broken', $status->state);
        $this->assertStringContainsString('sign in to Garmin again', $status->hint);
        $this->assertSame('2026-07-27T11:45:00', $status->authFailedAt);
        $this->assertTrue($status->needsSignIn());
        $this->assertSame(route('connect.garmin'), $status->toMcpArray()['sign_in_url']);
    }

    public function test_a_login_that_never_happened_reads_as_not_connected(): void
    {
        $status = DataStatus::evaluate(
            null,
            null,
            $this->authFailure('2026-07-27T11:45:00', 'NotConnected: no session stored'),
            $this->berlin('2026-07-27 12:00'),
        );

        $this->assertSame('not_connected', $status->state);
        $this->assertStringContainsString('not connected to Garmin yet', $status->hint);
        $this->assertTrue($status->needsSignIn());
        $this->assertSame(route('connect.garmin'), $status->toMcpArray()['sign_in_url']);
    }

    public function test_a_seeded_mirror_is_no_connection_however_fresh_its_log_reads(): void
    {
        // A Quickstart installation minutes after seed_demo.py: a fetch
        // that could not look newer, written by the seeder itself, and no
        // Garmin account anywhere near it. Every other input here says
        // "fresh", which is exactly why the seed has to be asked.
        $status = DataStatus::evaluate(
            '2026-07-27T11:30:00',
            $this->berlin('2026-07-27 11:00'),
            null,
            $this->berlin('2026-07-27 12:00'),
            seeded: true,
        );

        $this->assertSame('not_connected', $status->state);
        $this->assertStringContainsString('demo seed', $status->hint);
        $this->assertTrue($status->needsSignIn());
        $this->assertSame(route('connect.garmin'), $status->toMcpArray()['sign_in_url']);
    }

    public function test_a_broken_session_outranks_the_seed(): void
    {
        // Somebody who seeded a mirror and then connected for real. That
        // the numbers underneath came from the seeder is old news; that
        // the session they signed in with has stopped working is not.
        $status = DataStatus::evaluate(
            '2026-07-27T11:30:00',
            $this->berlin('2026-07-27 11:00'),
            $this->authFailure('2026-07-27T11:45:00'),
            $this->berlin('2026-07-27 12:00'),
            seeded: true,
        );

        $this->assertSame('auth_broken', $status->state);
    }

    public function test_a_stale_fetch_is_not_a_sign_in_problem(): void
    {
        // The distinction the whole sign-in prompt hangs on: a stopped
        // fetch job looks just as old, but signing in fixes nothing.
        $status = DataStatus::evaluate(
            '2026-07-26T13:00:00',
            $this->berlin('2026-07-27 06:00'),
            null,
            $this->berlin('2026-07-27 12:00'),
        );

        $this->assertFalse($status->needsSignIn());
        $this->assertArrayNotHasKey('sign_in_url', $status->toMcpArray());
    }

    public function test_the_mcp_array_drops_nulls_and_keeps_the_essentials(): void
    {
        $fresh = DataStatus::evaluate(
            '2026-07-27T11:30:00',
            $this->berlin('2026-07-27 11:00'),
            null,
            $this->berlin('2026-07-27 12:00'),
        );

        $array = $fresh->toMcpArray();

        $this->assertSame('fresh', $array['state']);
        $this->assertArrayNotHasKey('hint', $array);
        $this->assertArrayNotHasKey('auth_error', $array);
        $this->assertArrayHasKey('watch_last_sync', $array);
    }
}
