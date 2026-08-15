<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Garmin\Mirror;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Keeps one test's Garmin mirror out of the next one's.
 *
 * Until the move to Postgres nothing created a mirror for the test run at
 * all: the garmin connection pointed at data/garmin.db, SQLite opened
 * whatever file was there, and so every test that renders the dashboard was
 * quietly reading the developer's own health data. It passed on that one
 * machine and would have failed on the first clone, which is not a suite
 * anybody else can run.
 *
 * Since the mirror became per athlete, nothing is built here up front. A
 * test that reads the dashboard reaches App\Garmin\Mirror, which provisions
 * that athlete's schema from fetcher/schema.sql the first time it is asked,
 * exactly as a new account does in production. So the schema under test is
 * the one the fetcher writes, a column added there cannot drift away from
 * what the tests see, and the path that creates it is covered by every test
 * that uses it rather than by a fixture beside it.
 *
 * What is left for this trait is cleaning up. A test that seeds rows says
 * so, and the next test drops that mirror before it starts, which is the
 * only certain way: the mirror deliberately sits outside the transaction
 * RefreshDatabase rolls back, because DDL inside it deadlocks the mirror
 * connection (see Mirror::ensure).
 */
trait CreatesMirrorSchema
{
    /**
     * Mirrors a test filled and the next test therefore has to drop.
     *
     * Static because it outlives the test object, and keyed by tenant
     * because that is what a mirror is now. Nothing that only reads an
     * empty mirror lands in here, so the common case still builds the
     * schema once for the whole run.
     *
     * @var array<int, true>
     */
    private static array $dirtyMirrors = [];

    /**
     * Whether this process has taken care of what an earlier one left.
     *
     * A run cleans up after each of its own tests, but the last test of a
     * run has no successor to clean up after it, and the mirror sits
     * outside the transaction that rolls everything else back. So the
     * mirrors of a previous run are still there when the next one starts,
     * and its first test would read rows nobody in it wrote.
     */
    private static bool $sweptOldRuns = false;

    /** Drop whatever the previous test, or the previous run, left behind. */
    protected function dropDirtyMirrors(): void
    {
        $mirror = DB::connection('garmin');

        if (! self::$sweptOldRuns) {
            self::$sweptOldRuns = true;

            $left = $mirror->select("select nspname from pg_namespace where nspname ~ '^garmin_t[0-9]+$'");

            foreach ($left as $schema) {
                $mirror->statement('drop schema if exists '.$schema->nspname.' cascade');
            }

            // The roles outlive the schemas, and both are keyed by a user id
            // that the next run counts from 1 again. Left alone they pile up:
            // a role per user the suite ever created.
            //
            // The prefix comes from config rather than being written out,
            // because roles are cluster-wide: on a laptop that runs the real
            // installation beside the suite, the two share a database server,
            // and a sweep matching the default prefix drops the role the
            // dashboard reads through. phpunit.xml gives the suite its own.
            $roles = $mirror->select(
                'select rolname from pg_roles where rolname ~ ?',
                ['^'.Mirror::readerPrefix().'[0-9]+$']
            );

            foreach ($roles as $role) {
                $mirror->statement('drop owned by '.$role->rolname);
                $mirror->statement('drop role '.$role->rolname);
            }

            // The shared schema goes too, rather than only its rows. It is
            // what a laptop has that a fresh clone does not, and a run that
            // keeps it is a run testing a database nobody else has: the
            // grants of a new mirror name garmin_private, and on a machine
            // where it has existed since the first run ever, a statement that
            // cannot cope with its absence passes locally and fails on CI.
            // Which is exactly how it went once. So every run starts where CI
            // starts, and fetcher/schema.sql makes it again.
            $mirror->statement('drop schema if exists garmin_private cascade');
        }

        foreach (array_keys(self::$dirtyMirrors) as $tenant) {
            $mirror->statement('drop schema if exists '.Mirror::schema($tenant).' cascade');
        }

        // Every one of them, not only the dirty tenants'. A session row
        // says "this athlete has connected Garmin", which is what decides
        // who the scheduled fetch reaches, and it lives in the shared
        // schema that no rollback touches. One test's sign-in would
        // otherwise still be connected in the next.
        if ($mirror->selectOne("select 1 as found from pg_tables where schemaname = 'garmin_private' and tablename = 'garmin_session'")) {
            $mirror->table('garmin_private.garmin_session')->delete();
        }

        self::$dirtyMirrors = [];
        Mirror::forget();
    }

    /**
     * Puts fixture rows into one of the mirror's tables.
     *
     * The athlete's own mirror unless another user is named, because that
     * is whose data a test is almost always describing. Naming one is how
     * the tenancy tests give two athletes different histories.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    protected function seedMirror(string $table, array $rows, ?User $for = null): void
    {
        $tenant = ($for ?? $this->athlete())->id;

        // Provisioned as the fetcher would leave it, then written as the
        // fetcher writes it: with the tenant's reader out of the way, since
        // that role may select and nothing else.
        Mirror::ensure($tenant);
        Mirror::unpin();

        $connection = DB::connection('garmin');
        $connection->statement('set search_path = '.Mirror::schema($tenant));
        $connection->table($table)->insert($rows);

        self::$dirtyMirrors[$tenant] = true;
    }

    /**
     * Called by UsesTestMirror once it has put its own schema in place, so
     * that whatever runs next rebuilds the real one instead of asserting
     * against another test's fixture.
     */
    public static function mirrorSchemaWasReplaced(int $tenant): void
    {
        self::$dirtyMirrors[$tenant] = true;
    }
}
