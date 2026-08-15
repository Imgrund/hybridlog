<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Garmin\QueryTables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesTestMirror;
use Tests\TestCase;

class QueryTablesTest extends TestCase
{
    use RefreshDatabase;
    use UsesTestMirror;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTestMirror();

        $this->mirror()->statement('create table days (date text primary key, steps integer)');
        $this->mirror()->statement('create table body_comp (date text primary key, weight_g integer)');
        $this->mirror()->statement('create index body_comp_weight on body_comp (weight_g)');

        $this->mirror()->table('days')->insert(['date' => '2026-07-01', 'steps' => 9000]);
        $this->mirror()->table('body_comp')->insert(['date' => '2026-07-01', 'weight_g' => 81_000]);
    }

    /**
     * @return list<array{string, list<string>}>
     */
    public static function statements(): array
    {
        return [
            'plain select' => ['select * from days', ['days']],
            'aliased table' => ['select b.weight_g from body_comp b', ['body_comp']],
            'quoted name' => ['select * from "body_comp"', ['body_comp']],
            'common table expression' => ['with x as (select * from body_comp) select * from x', ['body_comp']],
            'join' => ['select d.date from days d join body_comp b on b.date = d.date', ['body_comp', 'days']],
            'subquery in the select list' => ['select (select count(*) from body_comp) as n from days', ['body_comp', 'days']],
            'union' => ['select date from days union select date from body_comp', ['body_comp', 'days']],
            'covering index only' => ['select count(weight_g) from body_comp', ['body_comp']],
            'no table at all' => ['select 1', []],
        ];
    }

    /**
     * @param  list<string>  $expected
     */
    #[DataProvider('statements')]
    public function test_it_names_the_tables_a_statement_really_reads(string $sql, array $expected): void
    {
        $tables = app(QueryTables::class)->for($sql);
        sort($tables);

        $this->assertSame($expected, $tables);
    }

    public function test_a_column_alias_is_not_mistaken_for_a_table(): void
    {
        // The text-matching guard this replaced refused this statement even
        // though it reads nothing but the days table.
        $this->assertSame(['days'], app(QueryTables::class)->for('select steps as body_comp from days'));
    }

    public function test_a_string_literal_is_not_mistaken_for_a_table(): void
    {
        $this->assertSame(['days'], app(QueryTables::class)->for("select 'body_comp' as label from days"));
    }

    public function test_broken_sql_raises_rather_than_reporting_no_tables(): void
    {
        // Returning [] here would read as "touches nothing protected" and
        // wave the statement through.
        $this->expectException(PDOException::class);

        app(QueryTables::class)->for('select * from');
    }
}
