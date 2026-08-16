<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\GarminHealthServer;
use App\Mcp\Tools\DescribeSchemaTool;
use App\Mcp\Tools\QueryHealthDataTool;
use App\Models\ConnectorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesTestMirror;
use Tests\TestCase;

/**
 * What keeps a model from tripping over Postgres, and what catches it
 * when it does anyway.
 *
 * Nearly every measurement column is double precision, and the habit a
 * model brings along (from SQLite and MySQL) is round(expr, 1), which
 * Postgres only defines for numeric. The mirror now ships that overload
 * itself (fetcher/schema.sql), so the habit simply works; on a mirror
 * the fetcher has not touched since, the schema notes still warn and
 * the query error still names the cast.
 *
 * The other stumble is a column the model remembered rather than read:
 * a plausible name that does not exist. Postgres' own "Perhaps you
 * meant" stays silent unless the name is an edit away, so the error
 * names the closest columns that do exist instead of leaving the retry
 * to another guess.
 */
class QueryDialectHintTest extends TestCase
{
    use RefreshDatabase, UsesTestMirror;

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete();
    }

    public function test_round_on_double_precision_works_on_a_provisioned_mirror(): void
    {
        $this->seedMirror('activities', [
            ['id' => 1, 'date' => '2026-08-15', 'duration_s' => 3750.0],
        ]);

        $response = GarminHealthServer::tool(QueryHealthDataTool::class, [
            'sql' => 'select round(duration_s / 60, 1) as habit, round((duration_s)::numeric, 1) as cast from activities',
        ]);

        // Both spellings answer: the shim takes the model's habit, and the
        // explicit cast keeps resolving to pg_catalog exactly as before.
        $response->assertOk()
            ->assertSee('62.5')
            ->assertSee('3750.0');
    }

    public function test_a_round_on_double_precision_names_the_cast_where_the_shim_is_missing(): void
    {
        $this->useTestMirror();
        $this->mirror()->statement('create table acts (id integer primary key, duration_s double precision)');
        $this->mirror()->table('acts')->insert(['id' => 1, 'duration_s' => 3723.0]);

        $response = GarminHealthServer::tool(QueryHealthDataTool::class, [
            'sql' => 'select round(duration_s / 60, 1) as minutes from acts',
        ]);

        $response->assertHasErrors()
            ->assertSee('round((expr)::numeric, 1)');
    }

    public function test_an_invented_column_gets_pointed_at_the_real_ones(): void
    {
        $this->useTestMirror();
        $this->mirror()->statement(
            'create table readiness (date text primary key, sleep_score_factor integer, sleep_history_factor integer, hrv_factor integer)'
        );

        $response = GarminHealthServer::tool(QueryHealthDataTool::class, [
            'sql' => 'select date, sleep_factor_feedback from readiness',
        ]);

        // The invented name recombines real fragments, so plain edit
        // distance would stay silent; the token overlap finds both
        // columns it was built from.
        $response->assertHasErrors()
            ->assertSee('no column named "sleep_factor_feedback"')
            ->assertSee('readiness.sleep_score_factor')
            ->assertSee('readiness.sleep_history_factor')
            ->assertSee('describe-schema');
    }

    public function test_an_invented_column_without_lookalikes_still_points_at_describe_schema(): void
    {
        $this->useTestMirror();
        $this->mirror()->statement('create table acts (id integer primary key)');

        $response = GarminHealthServer::tool(QueryHealthDataTool::class, [
            'sql' => 'select vo2max from acts',
        ]);

        $response->assertHasErrors()
            ->assertSee('no column named "vo2max"')
            ->assertSee('describe-schema');
    }

    public function test_the_column_hint_respects_the_body_metrics_switch(): void
    {
        $this->useTestMirror();
        $this->mirror()->statement('create table body_comp (date text primary key, muscle_mass_g double precision)');
        $this->mirror()->statement('create table acts (id integer primary key, mass_g double precision)');

        $sql = 'select muscle_mass from acts';

        // With body metrics shared, the closest column may come from
        // body_comp; with them switched off, the hint hides that table
        // exactly as describe-schema does.
        GarminHealthServer::tool(QueryHealthDataTool::class, ['sql' => $sql])
            ->assertHasErrors()
            ->assertSee('body_comp.muscle_mass_g');

        ConnectorSettings::for($this->athlete())->update(['share_body_metrics' => false]);

        GarminHealthServer::tool(QueryHealthDataTool::class, ['sql' => $sql])
            ->assertHasErrors()
            ->assertSee('acts.mass_g')
            ->assertDontSee('body_comp');
    }

    public function test_the_schema_notes_drop_the_round_warning_where_the_shim_exists(): void
    {
        // Reaching the tool provisions the mirror from fetcher/schema.sql,
        // shim included, so the warning would now claim a failure that
        // cannot happen.
        $response = GarminHealthServer::tool(DescribeSchemaTool::class, []);

        $response->assertOk()->assertDontSee('round((expr)::numeric, 1)');
    }

    public function test_the_schema_notes_warn_about_round_where_the_shim_is_missing(): void
    {
        $this->useTestMirror();

        $response = GarminHealthServer::tool(DescribeSchemaTool::class, []);

        $response->assertOk()->assertSee('round((expr)::numeric, 1)');
    }
}
