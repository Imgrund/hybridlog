<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ConnectsGarmin;
use Tests\Concerns\CreatesMirrorSchema;

abstract class TestCase extends BaseTestCase
{
    use ConnectsGarmin;
    use CreatesMirrorSchema;

    /** The installation owner the test acts as, once asked for. */
    private ?User $athlete = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->restartUserIds();
        $this->dropDirtyMirrors();
    }

    /**
     * The installation owner most tests act as: the one whose rows the
     * pre-tenancy tests took for granted. Created on first use; console
     * paths (scheduled senders, stdio MCP tools) resolve him via
     * User::owner(), web requests still need an explicit actingAs.
     */
    protected function athlete(): User
    {
        return $this->athlete ??= User::factory()->admin()->create();
    }

    /**
     * Let every test count its users from 1.
     *
     * Postgres sequences are not rolled back with the transaction that used
     * them, so without this the ids climb across the whole run and the first
     * user of the four-hundredth test is number 400. That used to be
     * harmless. Now a user id names a mirror schema, and a run that never
     * repeats an id would build four hundred of them, each with the
     * fetcher's seventeen tables, and leave them behind.
     *
     * ALTER SEQUENCE ... RESTART is transactional, unlike setval, so this
     * lasts exactly as long as the test does. Only inside the transaction
     * RefreshDatabase opened: without one it would reset a sequence for
     * real, and the next insert would collide with a row that is still
     * there.
     */
    private function restartUserIds(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::statement('alter sequence users_id_seq restart with 1');
        }
    }
}
