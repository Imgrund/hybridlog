<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\GarminHealthServer;
use App\Mcp\Tools\DescribeSchemaTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesTestMirror;
use Tests\TestCase;

/**
 * What describe-schema says about the data that is not there.
 *
 * A date range alone reads as complete: a sleep table spanning 120 days
 * with 115 rows looks whole, and a model that finds nothing for one of
 * the holes concludes the athlete did not sleep. The schema answer now
 * counts the holes, names the recent ones, and lists the fetches that
 * failed and were never made good, so "no row" can be read as "no data"
 * instead of as a finding.
 */
class SchemaGapsTest extends TestCase
{
    use RefreshDatabase, UsesTestMirror;

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete();
    }

    public function test_missing_days_are_counted_and_named(): void
    {
        // Six calendar days, two of them without a row: the exact case
        // where naming the dates is the information.
        $this->seedMirror('sleep', [
            ['date' => '2026-08-10'],
            ['date' => '2026-08-11'],
            ['date' => '2026-08-14'],
            ['date' => '2026-08-15'],
        ]);

        GarminHealthServer::tool(DescribeSchemaTool::class, [])
            ->assertOk()
            ->assertSee('"days_missing_in_range":2')
            ->assertSee('2026-08-12')
            ->assertSee('2026-08-13');
    }

    public function test_many_missing_days_are_counted_but_not_listed(): void
    {
        // Two rows two months apart: reporting the count keeps the answer
        // honest, listing every date would flood it.
        $this->seedMirror('sleep', [
            ['date' => '2026-01-01'],
            ['date' => '2026-03-01'],
        ]);

        GarminHealthServer::tool(DescribeSchemaTool::class, [])
            ->assertOk()
            ->assertSee('"days_missing_in_range":58')
            ->assertDontSee('"missing_dates":');
    }

    public function test_tables_not_keyed_by_date_are_not_judged_by_calendar(): void
    {
        // Activities has quiet days by design; a calendar-coverage number
        // there would flag every rest day as a hole.
        $this->seedMirror('activities', [
            ['id' => 1, 'date' => '2026-08-10'],
            ['id' => 2, 'date' => '2026-08-15'],
        ]);

        // With a colon: the field itself, not the notes line explaining it.
        GarminHealthServer::tool(DescribeSchemaTool::class, [])
            ->assertOk()
            ->assertDontSee('"days_missing_in_range":');
    }

    public function test_failed_fetches_are_reported_and_recovered_ones_are_not(): void
    {
        // fetch_log is upserted on (date, kind), so a row still standing
        // with ok = 0 was never fetched successfully afterwards. The
        // recovered day's row must stay out of the report.
        $this->seedMirror('fetch_log', [
            ['date' => '2026-08-10', 'kind' => 'sleep', 'ok' => 0, 'error' => 'HTTPError: 500 Server Error'],
            ['date' => '2026-08-11', 'kind' => 'stats', 'ok' => 1, 'error' => 'must-not-appear'],
        ]);

        GarminHealthServer::tool(DescribeSchemaTool::class, [])
            ->assertOk()
            ->assertSee('fetch_errors')
            ->assertSee('"open":1')
            ->assertSee('HTTPError: 500 Server Error')
            ->assertSee('Treat those days as unknown')
            ->assertDontSee('must-not-appear');
    }

    public function test_a_clean_log_reports_no_fetch_errors_section(): void
    {
        $this->seedMirror('fetch_log', [
            ['date' => '2026-08-11', 'kind' => 'stats', 'ok' => 1, 'error' => null],
        ]);

        GarminHealthServer::tool(DescribeSchemaTool::class, [])
            ->assertOk()
            ->assertDontSee('fetch_errors');
    }
}
