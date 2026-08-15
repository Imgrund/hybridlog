<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Garmin\Mirror;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * The one migration that runs against real data: the one that turns
 * the installation's single mirror into tenant #1's.
 *
 * Everywhere else the suite sees mirrors that were created per athlete
 * from the start, which is the case that cannot go wrong. Production is
 * the other case: a schema named `garmin` holding months of health
 * history, and one shot at renaming it, so this test builds that shape
 * and runs the migration over it.
 *
 * A pg_dump of the mirror belongs before this migration on any
 * installation with data. Not because the rename is risky in itself, it
 * is atomic and copies nothing, but because everything after it assumes
 * the old name is gone.
 */
class MirrorRenameTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_08_13_210000_give_the_mirror_a_tenant.php');
    }

    /**
     * The installation as it looked before multi-tenancy: one schema named
     * garmin, with a day in it, and one session row under the fixed id 1.
     */
    private function buildSingleAthleteMirror(): void
    {
        Mirror::unpin();
        $db = DB::connection('garmin');

        // This test owns the whole mirror namespace while it runs: it is
        // about an installation that has exactly one schema, named the way
        // it was named before tenancy, and a leftover garmin_t1 from a
        // sibling test would look to the migration like a second mirror it
        // must not choose between.
        $this->dropEveryMirror();
        $db->statement('drop schema if exists garmin cascade');
        $db->unprepared(str_replace(
            '{mirror}',
            'garmin',
            (string) file_get_contents(base_path('fetcher/schema.sql'))
        ));

        $db->statement('set search_path = garmin');
        $db->table('days')->insert([
            'date' => '2026-08-01',
            'steps' => 12345,
            'fetched_at' => '2026-08-01T09:31:00',
        ]);

        $db->table('garmin_private.garmin_session')->insert([
            'id' => 1,
            'tokens' => str_repeat('t', 600),
            'updated_at' => '2026-08-01T09:30:00',
        ]);
    }

    protected function tearDown(): void
    {
        // The mirror sits outside the transaction that rolls the rest back,
        // so what this test built it also takes away: the old schema name if
        // the migration never got to it, whatever the migration renamed it
        // to, and the session rows, which live in the one schema that is
        // shared between tenants.
        Mirror::unpin();
        DB::connection('garmin')->statement('drop schema if exists garmin cascade');
        DB::connection('garmin')->table('garmin_private.garmin_session')->delete();
        $this->dropEveryMirror();

        parent::tearDown();
    }

    private function dropEveryMirror(): void
    {
        $db = DB::connection('garmin');

        foreach ($db->select("select nspname from pg_namespace where nspname ~ '^garmin_t[0-9]+$'") as $schema) {
            $db->statement('drop schema if exists '.$schema->nspname.' cascade');
        }

        // The roles too, and not only for tidiness: a reader left behind
        // without its schema is a role holding no privileges, which is the
        // state a half-provisioned installation is in, and the next test
        // would be starting from it by accident rather than on purpose.
        // Prefix from config, not written out: see CreatesMirrorSchema for
        // why a sweep that assumes the default drops somebody's real role.
        $pattern = '^'.Mirror::readerPrefix().'[0-9]+$';

        foreach ($db->select('select rolname from pg_roles where rolname ~ ?', [$pattern]) as $role) {
            $db->statement('drop owned by '.$role->rolname);
            $db->statement('drop role '.$role->rolname);
        }

        Mirror::forget();
    }

    public function test_the_installations_mirror_becomes_the_owners(): void
    {
        $owner = $this->athlete();
        $this->buildSingleAthleteMirror();

        $this->migration()->up();

        $schema = Mirror::schema($owner->id);

        $this->assertNull(
            DB::selectOne('select 1 as found from pg_namespace where nspname = ?', ['garmin']),
            'the old schema name is still there'
        );

        // The history is the same rows, not a copy: a rename moves the
        // tables themselves, which is the whole reason for choosing one.
        Mirror::unpin();
        $db = DB::connection('garmin');
        $db->statement("set search_path = {$schema}");

        $this->assertSame(12345, (int) $db->table('days')->where('date', '2026-08-01')->value('steps'));
    }

    public function test_the_owner_can_read_their_mirror_through_the_reader_role(): void
    {
        $owner = $this->athlete();
        $this->buildSingleAthleteMirror();

        $this->migration()->up();

        // Not merely renamed: granted. The rename alone would leave the
        // tenant's reader role without a single privilege, and every read
        // through the connection bootstrap would end in permission denied.
        $this->assertSame(
            12345,
            (int) Mirror::forTenant($owner->id)->table('days')->where('date', '2026-08-01')->value('steps')
        );

        $this->assertTrue(Mirror::isIsolated(Mirror::forTenant($owner->id), $owner->id));
    }

    public function test_a_second_account_gets_an_empty_mirror_rather_than_the_owners(): void
    {
        $owner = $this->athlete();
        $invited = User::factory()->create();
        $this->buildSingleAthleteMirror();

        $this->migration()->up();

        $this->assertNotNull(
            DB::selectOne('select 1 as found from pg_namespace where nspname = ?', [Mirror::schema($invited->id)]),
            'the invited account got no mirror at all'
        );

        $this->assertSame(0, Mirror::forTenant($invited->id)->table('days')->count());
        $this->assertSame(1, Mirror::forTenant($owner->id)->table('days')->count());
    }

    public function test_the_garmin_session_follows_the_owner(): void
    {
        // The id of that row is the tenant now (fetcher/schema.sql), so an
        // installation whose owner is not user 1 has to have it moved, or
        // the next fetch would look for a session nobody wrote.
        User::factory()->create();
        User::factory()->create();
        $owner = User::factory()->admin()->create();
        $this->assertNotSame(1, $owner->id);

        $this->buildSingleAthleteMirror();

        $this->migration()->up();

        Mirror::unpin();
        $sessions = DB::connection('garmin')->table('garmin_private.garmin_session');

        $this->assertSame(0, $sessions->clone()->where('id', 1)->count());
        $this->assertSame(1, $sessions->clone()->where('id', $owner->id)->count());
    }

    public function test_it_refuses_to_choose_between_two_mirrors(): void
    {
        $owner = $this->athlete();
        $this->buildSingleAthleteMirror();

        // Both names present: whichever holds the real history, this
        // migration cannot tell, and merging them would be a guess with
        // somebody's health record in it.
        Mirror::ensure($owner->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot tell which/');

        $this->migration()->up();
    }
}
