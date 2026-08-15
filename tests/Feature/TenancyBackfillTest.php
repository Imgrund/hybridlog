<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * The one migration that runs against real data: the one that adopts
 * every pre-tenancy row into the owner's account.
 *
 * The suite otherwise only ever sees the migration build an empty
 * database, which is the case that cannot fail. Production is the other
 * case (nine populated tables and exactly one account) and it gets one
 * shot. So this test undoes the migration, refills the tables the way
 * the installation looked before it, and runs it forward again.
 */
class TenancyBackfillTest extends TestCase
{
    use RefreshDatabase;

    /** The tables the migration adopts, with a row each to adopt. */
    private const LEGACY_ROWS = [
        'athlete_profiles' => ['locale' => 'de'],
        'connector_settings' => ['share_health_data' => true],
        'connector_guidelines' => ['guideline' => 'Always name the number.'],
        'symptom_log' => ['date' => '2026-08-01', 'logged_at' => '2026-08-01 12:00:00', 'symptom' => 'scratchy throat'],
        'health_alerts' => ['rule' => 'readiness', 'date' => '2026-08-01', 'message' => 'Readiness 21.'],
        'garmin_login_attempts' => ['status' => 'succeeded'],
        'mcp_tool_calls' => ['tool' => 'get-health-summary-tool', 'transport' => 'web', 'duration_ms' => 12, 'ok' => true],
        'push_subscriptions' => ['endpoint' => 'https://push.example/p/1', 'endpoint_hash' => 'a1b2'],
        'push_sends' => ['kind' => 'briefing', 'date' => '2026-08-01', 'sent_at' => '2026-08-01 09:40:00', 'devices' => 1],
    ];

    private function migration(): object
    {
        return require database_path('migrations/2026_08_13_140000_add_user_scope_for_multi_tenancy.php');
    }

    /** The database as it stood before this branch, with rows in it. */
    private function rewindToSingleAthlete(): void
    {
        $this->migration()->down();

        foreach (self::LEGACY_ROWS as $table => $row) {
            DB::table($table)->insert($row + ['created_at' => now()]);
        }
    }

    public function test_the_rows_of_the_installation_become_the_owners(): void
    {
        $owner = User::factory()->create();
        $this->rewindToSingleAthlete();

        // The account exists but is nobody's owner yet: is_admin left
        // with down(), and granting it is the migration's own first job.
        $this->assertFalse(Schema::hasColumn('users', 'is_admin'));

        $this->migration()->up();

        $this->assertTrue(User::query()->whereKey($owner->id)->value('is_admin'));

        foreach (array_keys(self::LEGACY_ROWS) as $table) {
            $this->assertSame(
                [$owner->id],
                DB::table($table)->pluck('user_id')->unique()->values()->all(),
                "rows in {$table} were not adopted"
            );
        }
    }

    public function test_a_second_account_never_inherits_the_first_ones_rows(): void
    {
        // The order the accounts were created in decides, not the order
        // the rows were written: everything predating tenancy belongs to
        // whoever was here first.
        $owner = User::factory()->create();
        $later = User::factory()->create();
        $this->rewindToSingleAthlete();

        $this->migration()->up();

        $this->assertTrue(User::query()->whereKey($owner->id)->value('is_admin'));
        $this->assertFalse(User::query()->whereKey($later->id)->value('is_admin'));
        $this->assertSame(0, DB::table('symptom_log')->where('user_id', $later->id)->count());
    }

    public function test_a_row_without_a_tenant_cannot_survive_the_migration(): void
    {
        User::factory()->create();
        $this->rewindToSingleAthlete();
        $this->migration()->up();

        // The point of the NOT NULL: from here on the database itself
        // refuses a row that belongs to nobody.
        $this->expectException(QueryException::class);

        DB::table('symptom_log')->insert([
            'date' => '2026-08-02', 'logged_at' => '2026-08-02 12:00:00',
            'symptom' => 'orphan', 'created_at' => now(),
        ]);
    }

    public function test_the_duplicate_singletons_of_an_old_installation_are_dropped(): void
    {
        // firstOrCreate([]) only ever matched the first row, so a second
        // one could be written and then never read again. It has to go,
        // or the per-user unique cannot be added.
        $owner = User::factory()->create();
        $this->rewindToSingleAthlete();
        DB::table('connector_settings')->insert(['share_health_data' => false, 'created_at' => now()]);

        $this->migration()->up();

        $row = DB::table('connector_settings')->get();
        $this->assertCount(1, $row);
        $this->assertTrue((bool) $row->first()->share_health_data, 'the surviving row must be the one that was in use');
        $this->assertSame($owner->id, $row->first()->user_id);
    }

    public function test_rows_without_any_account_stop_the_migration_rather_than_guess(): void
    {
        // An installation with data but no user has nobody to adopt the
        // rows. Refusing beats guessing and beats deleting: whoever runs
        // this creates the owner account first.
        $this->rewindToSingleAthlete();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no user exists to own them');

        $this->migration()->up();
    }
}
