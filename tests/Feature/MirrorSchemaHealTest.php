<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Garmin\Mirror;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesMirrorSchema;
use Tests\TestCase;

/**
 * schema.sql must survive a mirror that predates its late columns.
 *
 * The suite always builds the mirror from scratch, where CREATE TABLE
 * carries every column and the COMMENTs at the end always find their
 * target. A production mirror is older: there CREATE IF NOT EXISTS is a
 * no-op and only the ALTER ... ADD COLUMN IF NOT EXISTS steps supply
 * what is missing, and because the whole file runs as one implicit
 * transaction, a COMMENT sitting before its column's ALTER kills the
 * entire load and with it every fetch. That is exactly how production
 * broke on 2026-07-30; this test replays that shape of mirror.
 */
class MirrorSchemaHealTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_schema_heals_a_mirror_from_before_the_late_columns(): void
    {
        $tenant = $this->athlete()->id;
        $schema = Mirror::schema($tenant);

        // Provisioned first: that is what puts the pristine schema there,
        // and it is the same file under test, executed the way the fetcher
        // executes it.
        Mirror::ensure($tenant);
        Mirror::unpin();
        $db = DB::connection('garmin');

        // Dropping the late columns turns it into the installation from
        // before they existed.
        $db->unprepared(
            "alter table {$schema}.days"
            .' drop column if exists sweat_loss_ml,'
            .' drop column if exists hydration_goal_ml,'
            .' drop column if exists hydration_value_ml'
        );

        // The point of the test: the same file the fetcher executes on
        // every run must go through without throwing on that mirror. It
        // names no schema of its own since the mirror became per athlete,
        // so it is substituted here exactly as fetch.py substitutes it.
        $db->unprepared(str_replace(
            '{mirror}',
            $schema,
            (string) file_get_contents(base_path('fetcher/schema.sql'))
        ));

        $columns = collect($db->select(
            'select column_name from information_schema.columns'
            .' where table_schema = ? and table_name = ?',
            [$schema, 'days']
        ))->pluck('column_name');

        $this->assertTrue($columns->contains('sweat_loss_ml'));
        $this->assertTrue($columns->contains('hydration_goal_ml'));
        $this->assertTrue($columns->contains('hydration_value_ml'));

        // The healed mirror is not the one the suite built: whoever runs
        // next rebuilds from scratch instead of trusting it.
        CreatesMirrorSchema::mirrorSchemaWasReplaced($tenant);
    }
}
