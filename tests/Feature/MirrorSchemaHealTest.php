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
        // before they existed. Every table that has grown one is listed:
        // the failure mode is a COMMENT written above its own ALTER, and
        // that is invisible until a mirror without the column runs the file.
        $late = [
            'days' => [
                'sweat_loss_ml', 'hydration_goal_ml', 'hydration_value_ml',
                'stress_low_s', 'stress_medium_s', 'stress_high_s',
                'stress_activity_s', 'stress_qualifier', 'bb_at_wake',
                'bb_during_sleep', 'resting_hr_7d_avg',
            ],
            'sleep' => [
                'skin_temp_deviation_c', 'avg_stress', 'avg_hr', 'awake_count',
                'restless_moments', 'breathing_disruptions',
                'breathing_disruption_severity', 'spo2_avg', 'spo2_lowest',
                'body_battery_change', 'need_actual_min', 'need_baseline_min',
                'midpoint_min', 'optimal_window_start_min',
                'optimal_window_end_min', 'alignment_status',
            ],
            'readiness' => [
                'feedback_long', 'sleep_score', 'hrv_weekly_avg',
                'acwr_factor_feedback', 'hrv_factor_feedback',
                'sleep_score_factor_feedback', 'sleep_history_factor_feedback',
                'stress_history_factor_feedback', 'recovery_time_factor_feedback',
            ],
            'training_status' => ['balance_feedback', 'fitness_trend', 'fitness_trend_sport'],
        ];

        foreach ($late as $table => $columns) {
            $drops = collect($columns)->map(fn ($c) => "drop column if exists {$c}")->implode(', ');
            $db->unprepared("alter table {$schema}.{$table} {$drops}");
        }

        // raw_payload arrived whole rather than column by column, so the
        // older shape is its absence.
        $db->unprepared("drop table if exists {$schema}.raw_payload");

        // The point of the test: the same file the fetcher executes on
        // every run must go through without throwing on that mirror. It
        // names no schema of its own since the mirror became per athlete,
        // so it is substituted here exactly as fetch.py substitutes it.
        $db->unprepared(str_replace(
            '{mirror}',
            $schema,
            (string) file_get_contents(base_path('fetcher/schema.sql'))
        ));

        foreach ($late as $table => $expected) {
            $columns = collect($db->select(
                'select column_name from information_schema.columns'
                .' where table_schema = ? and table_name = ?',
                [$schema, $table]
            ))->pluck('column_name');

            foreach ($expected as $column) {
                $this->assertTrue(
                    $columns->contains($column),
                    "{$table}.{$column} was not restored by schema.sql"
                );
            }
        }

        $this->assertNotEmpty($db->select(
            'select 1 from information_schema.tables'
            .' where table_schema = ? and table_name = ?',
            [$schema, 'raw_payload']
        ), 'raw_payload was not restored by schema.sql');

        // The healed mirror is not the one the suite built: whoever runs
        // next rebuilds from scratch instead of trusting it.
        CreatesMirrorSchema::mirrorSchemaWasReplaced($tenant);
    }

    public function test_a_table_the_reader_cannot_read_is_granted_on_the_next_ensure(): void
    {
        $tenant = $this->athlete()->id;
        $schema = Mirror::schema($tenant);

        Mirror::ensure($tenant);
        Mirror::unpin();
        $db = DB::connection('garmin');

        // What a mirror looks like when schema.sql grew a table after the
        // grants were written: USAGE on the schema is intact, so the old
        // check called it readable, while the table itself is reachable by
        // nobody. describe-schema would list it and every select against it
        // would be refused, which reads as a broken tool rather than as a
        // missing GRANT.
        $reader = Mirror::reader($tenant);
        $db->unprepared("revoke select on {$schema}.raw_payload from {$reader}");

        $this->assertFalse($this->readerMayRead($db, $reader, $schema, 'raw_payload'));

        // ensure() is what every request already calls, so the repair costs
        // nobody an extra step. forget() only clears the in-process memo of
        // what is already provisioned, which is what makes it look again.
        Mirror::forget();
        Mirror::ensure($tenant);
        Mirror::unpin();

        $this->assertTrue(
            $this->readerMayRead($db, $reader, $schema, 'raw_payload'),
            'ensure() left a table the tenant reader cannot select from'
        );

        CreatesMirrorSchema::mirrorSchemaWasReplaced($tenant);
    }

    private function readerMayRead(mixed $db, string $reader, string $schema, string $table): bool
    {
        return (bool) $db->selectOne(
            "select has_table_privilege(?, ?, 'select') as ok",
            [$reader, "{$schema}.{$table}"]
        )->ok;
    }
}
