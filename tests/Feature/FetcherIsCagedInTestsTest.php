<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * No test may start the real fetcher.
 *
 * The queue is sync in the suite, so anything that dispatches
 * RunGarminFetch runs it in-process, and an athlete's first sign-in
 * dispatches one by design: App\Garmin\GarminLogin::fillTheNewMirror
 * starts a ninety-day backfill so a new mirror is not empty. With the
 * fetcher's default command that is a real conversation with Garmin
 * Connect, and fetch.py takes its connection from the environment it
 * inherits, so a laptop .env pointed it at the developer's own mirror
 * and the suite wrote live data into it.
 *
 * It went unseen in both places it could have been caught: CI has no
 * virtualenv, so the command died instantly, and locally the mirror's
 * reader role was missing its grants, so the check before the backfill
 * threw and the backfill never started. Fixing the grants is what
 * finally let the suite loose on Garmin.
 *
 * phpunit.xml cages both, and this is the test that says so out loud,
 * because the next person to add a variable there will not read the
 * comment.
 */
class FetcherIsCagedInTestsTest extends TestCase
{
    public function test_the_configured_fetcher_is_not_the_real_one(): void
    {
        foreach (['garmin.fetch.command', 'garmin.login.command'] as $key) {
            $command = (string) config($key);

            $this->assertNotSame('', $command, $key.' is empty, which falls back to the virtualenv fetcher.');
            $this->assertStringNotContainsString('fetch.py', $command, $key.' points at the real fetcher.');
            $this->assertStringNotContainsString('login.py', $command, $key.' points at the real login.');
        }
    }

    public function test_no_connection_to_a_real_mirror_is_left_lying_in_the_environment(): void
    {
        // What the fetcher would write through if it ever ran. Empty is the
        // only safe value: anything else is somebody's database.
        foreach (['GARMIN_FETCH_DSN', 'DATABASE_URL'] as $variable) {
            $this->assertSame(
                '',
                (string) getenv($variable),
                $variable.' is set in the test environment; a spawned fetcher would reach it.'
            );
        }
    }

    public function test_the_mirror_the_suite_uses_is_the_test_database(): void
    {
        // The other half of the same guarantee, for the connection the app
        // itself reads through rather than the one a subprocess would.
        $this->assertSame('garmin_test', config('database.connections.garmin.database'));
        $this->assertSame('garmin_test', config('database.connections.pgsql.database'));
    }
}
