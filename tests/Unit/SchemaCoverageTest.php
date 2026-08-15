<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Garmin\SchemaCoverage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesTestMirror;
use Tests\TestCase;

class SchemaCoverageTest extends TestCase
{
    use RefreshDatabase;
    use UsesTestMirror;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTestMirror();

        $this->mirror()->statement(
            'create table days (date text primary key, steps integer, spo2_avg integer, vo2max_cycling real)'
        );
        $this->mirror()->statement('create table empty_table (date text, value integer)');

        foreach (range(1, 30) as $i) {
            $this->mirror()->table('days')->insert([
                'date' => sprintf('2026-07-%02d', $i),
                'steps' => 8000 + $i,
                'spo2_avg' => null,
                // One filled row in thirty sits below the 5 % mark.
                'vo2max_cycling' => $i === 1 ? 48.0 : null,
            ]);
        }
    }

    public function test_a_column_the_device_never_records_is_named(): void
    {
        $coverage = app(SchemaCoverage::class)->for(['days']);

        $this->assertSame(['spo2_avg'], $coverage['days']['never_filled']);
    }

    public function test_a_barely_filled_column_is_reported_with_its_ratio(): void
    {
        $coverage = app(SchemaCoverage::class)->for(['days']);

        $this->assertSame(['vo2max_cycling (1/30)'], $coverage['days']['sparse']);
    }

    public function test_a_well_filled_column_is_not_mentioned_at_all(): void
    {
        $coverage = app(SchemaCoverage::class)->for(['days']);

        $this->assertStringNotContainsString('steps', json_encode($coverage));
    }

    public function test_the_covered_date_range_is_reported(): void
    {
        $coverage = app(SchemaCoverage::class)->for(['days']);

        $this->assertSame(30, $coverage['days']['rows']);
        $this->assertSame('2026-07-01 .. 2026-07-30', $coverage['days']['date_range']);
    }

    public function test_an_empty_table_reports_zero_rows_without_column_noise(): void
    {
        // Every column is null here, but calling them "never filled" would
        // be wrong: there is simply nothing in the table yet.
        $coverage = app(SchemaCoverage::class)->for(['empty_table']);

        $this->assertSame(['rows' => 0], $coverage['empty_table']);
    }

    public function test_an_unreadable_table_does_not_break_the_rest(): void
    {
        $coverage = app(SchemaCoverage::class)->for(['days', 'no_such_table']);

        $this->assertArrayHasKey('days', $coverage);
        $this->assertArrayNotHasKey('no_such_table', $coverage);
    }
}
