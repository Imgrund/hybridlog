<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\GarminHealthServer;
use App\Mcp\Tools\DescribeSchemaTool;
use App\Mcp\Tools\QueryHealthDataTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesTestMirror;
use Tests\TestCase;

/**
 * Whether a model can tell it is reading seeded demo data.
 *
 * The Quickstart's happy path is seed, look at the dashboard, connect an
 * AI. Without a marker the model reads the generated rows as the tester's
 * own health record and answers in the second person about a body that
 * does not exist. Every seeded row carries fetched_at='demo', so the
 * answer can carry the truth.
 */
class DemoNoticeTest extends TestCase
{
    use RefreshDatabase, UsesTestMirror;

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete();
    }

    public function test_answers_from_a_seeded_mirror_name_their_source(): void
    {
        $this->seedMirror('fetch_log', [
            ['date' => '2026-08-10', 'kind' => 'stats', 'ok' => 1, 'fetched_at' => 'demo'],
        ]);

        GarminHealthServer::tool(DescribeSchemaTool::class, [])
            ->assertOk()
            ->assertSee('data_source')
            ->assertSee('seeded demo data')
            ->assertSee('not a real athlete');
    }

    public function test_a_mirror_with_demo_rows_beside_fetched_ones_says_so(): void
    {
        // Connecting Garmin after seeding overwrites only the days the
        // fetch window reaches; the older rows stay generated. A blanket
        // "demo data" would then be as wrong as saying nothing.
        $this->seedMirror('fetch_log', [
            ['date' => '2026-05-10', 'kind' => 'stats', 'ok' => 1, 'fetched_at' => 'demo'],
            ['date' => '2026-08-10', 'kind' => 'stats', 'ok' => 1, 'fetched_at' => '2026-08-10T09:30:12'],
        ]);

        GarminHealthServer::tool(DescribeSchemaTool::class, [])
            ->assertOk()
            ->assertSee('alongside really fetched data');
    }

    public function test_really_fetched_mirrors_stay_unstamped(): void
    {
        $this->seedMirror('fetch_log', [
            ['date' => '2026-08-10', 'kind' => 'stats', 'ok' => 1, 'fetched_at' => '2026-08-10T09:30:12'],
        ]);

        GarminHealthServer::tool(DescribeSchemaTool::class, [])
            ->assertOk()
            ->assertDontSee('data_source');
    }

    public function test_a_mirror_without_the_bookkeeping_table_still_answers(): void
    {
        // The stamp must never break a tool call: a fixture mirror has no
        // fetch_log, and the answer simply arrives unstamped.
        $this->useTestMirror();
        $this->mirror()->statement('create table acts (id integer primary key, duration_s double precision)');
        $this->mirror()->table('acts')->insert(['id' => 1, 'duration_s' => 600.0]);

        GarminHealthServer::tool(QueryHealthDataTool::class, ['sql' => 'select duration_s from acts'])
            ->assertOk()
            ->assertSee('600')
            ->assertDontSee('data_source');
    }

    public function test_error_answers_are_not_stamped(): void
    {
        // An error text is read as an instruction to retry differently;
        // a data-source preamble in front of it would only dilute it.
        $this->seedMirror('fetch_log', [
            ['date' => '2026-08-10', 'kind' => 'stats', 'ok' => 1, 'fetched_at' => 'demo'],
        ]);

        GarminHealthServer::tool(QueryHealthDataTool::class, ['sql' => 'delete from days'])
            ->assertHasErrors()
            ->assertDontSee('data_source');
    }
}
