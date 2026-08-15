<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Garmin\Mirror;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Gives a test its own empty Garmin mirror.
 *
 * The real mirror grows every day, so any assertion about row counts, ratios
 * or budgets would rot within a week. Tests that care about the query layer
 * build their own fixture instead.
 *
 * Postgres has no ":memory:", so this drops and recreates the athlete's
 * mirror schema inside the test database rather than swapping in a different
 * engine. That is the point: this is the one place where SQL written by a
 * language model is executed, and testing it against a different dialect
 * than production would only prove that SQLite is forgiving.
 *
 * Two roles are in play here since the mirror became per athlete, and the
 * split is the same one production runs: a fixture is written by the role
 * the connection logs in as, and read back by the tenant's reader role,
 * which may select and nothing else. mirror() hands out the writing half.
 */
trait UsesTestMirror
{
    private function useTestMirror(?User $for = null): void
    {
        $tenant = ($for ?? $this->athlete())->id;
        $schema = Mirror::schema($tenant);

        // Through the mirror connection, not the app one. A test using
        // RefreshDatabase holds an open transaction on the app connection,
        // and "create schema" inside it takes a lock that the mirror
        // connection then waits on for as long as the test runs: the suite
        // does not fail, it stops. Both connections are the same role here
        // (see phpunit.xml), so the mirror can create its own schema, and
        // doing it on the connection that will use it keeps the DDL out of
        // any transaction.
        Mirror::unpin();
        $mirror = DB::connection('garmin');
        $mirror->statement("drop schema if exists {$schema} cascade");
        $mirror->statement("create schema {$schema}");
        $mirror->statement("set search_path = {$schema}");

        // Before the fixture's tables rather than after: what actually keeps
        // them readable is the default privileges inside this, and those only
        // reach a table created once they are in place. Dropping the schema
        // took the previous ones with it.
        Mirror::grant($tenant);

        // The real mirror is gone now, and the next test gets it back by
        // having this one dropped first. Without this the fixture would stay
        // behind and every later test would read a schema built for one
        // assertion.
        static::mirrorSchemaWasReplaced($tenant);
    }

    /**
     * The mirror as its writer: the role that owns the schema, pointed at
     * one athlete's, with no tenant reader in the way.
     *
     * Tests build their fixtures through this. What they are testing reads
     * through App\Garmin\Mirror instead and gets the reader role, so the
     * fixture never borrows the privileges of the thing under test.
     */
    private function mirror(?User $for = null): Connection
    {
        $tenant = ($for ?? $this->athlete())->id;

        Mirror::unpin();

        $connection = DB::connection('garmin');
        $connection->statement('set search_path = '.Mirror::schema($tenant));

        return $connection;
    }
}
