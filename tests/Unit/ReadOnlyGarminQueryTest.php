<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Garmin\ReadOnlyGarminQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesTestMirror;
use Tests\TestCase;

/**
 * The one class that runs SQL an AI wrote, so every guard gets a test.
 *
 * These run against Postgres because that is what production runs. The
 * privilege side of the defense (the reader role that cannot see the users
 * table) is not testable from here, since the suite connects as an owner;
 * database/postgres/verify_roles.sh covers that half.
 */
class ReadOnlyGarminQueryTest extends TestCase
{
    use RefreshDatabase;
    use UsesTestMirror;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTestMirror();

        $this->mirror()->statement('create table days (date text primary key, steps integer, note text)');

        foreach (range(1, 40) as $i) {
            $this->mirror()->table('days')->insert([
                'date' => sprintf('2026-07-%02d', $i),
                'steps' => 8000 + $i,
                'note' => str_repeat('x', 100),
            ]);
        }
    }

    private function reader(int $maxBytes = ReadOnlyGarminQuery::MAX_BYTES, int $maxSeconds = ReadOnlyGarminQuery::MAX_SECONDS): ReadOnlyGarminQuery
    {
        return new ReadOnlyGarminQuery($maxBytes, $maxSeconds);
    }

    public function test_a_plain_select_comes_back_with_columns_and_rows(): void
    {
        $result = $this->reader()->run('select date, steps from days order by date limit 3');

        $this->assertSame(['date', 'steps'], $result['columns']);
        $this->assertCount(3, $result['rows']);
        $this->assertSame('2026-07-01', $result['rows'][0]['date']);
        $this->assertFalse($result['truncated']);
        $this->assertArrayNotHasKey('truncated_by', $result);
    }

    public function test_a_common_table_expression_is_allowed(): void
    {
        // Models reach for WITH constantly for rolling averages, so refusing
        // it would push them into worse queries rather than safer ones.
        $result = $this->reader()->run(
            'with recent as (select * from days order by date desc limit 5) select count(*) as n from recent'
        );

        $this->assertSame(5, (int) $result['rows'][0]['n']);
    }

    /**
     * @return list<array{string}>
     */
    public static function writingStatements(): array
    {
        return [
            ["insert into days (date) values ('2026-01-01')"],
            ['update days set steps = 0'],
            ['delete from days'],
            ['drop table days'],
            ['create table evil (x integer)'],
            ['alter table days add column x integer'],
            ['truncate days'],
            ['grant select on days to public'],
            // Caught by the leading-keyword check rather than the blocklist,
            // which is why it is here and its CTE-disguised twin is not.
            ["copy (select 1) to program 'id'"],
        ];
    }

    #[DataProvider('writingStatements')]
    public function test_a_statement_that_is_not_a_select_is_refused(string $sql): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->reader()->run($sql);
    }

    public function test_a_second_statement_smuggled_behind_a_semicolon_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->reader()->run('select 1; drop table days');
    }

    public function test_a_single_trailing_semicolon_is_tolerated(): void
    {
        // Not an attack, just how a model ends a statement out of habit.
        $result = $this->reader()->run('select count(*) as n from days;  ');

        $this->assertSame(40, (int) $result['rows'][0]['n']);
    }

    /**
     * Postgres-specific escapes. Every one of these is a syntactically valid
     * single statement with no semicolon in it, which is exactly why the
     * blocklist has to name them.
     *
     * @return array<string, array{string, string}>
     */
    public static function forbiddenConstructs(): array
    {
        return [
            'read a host file' => ["select pg_read_file('/etc/passwd')", 'file read'],
            'read a host file, schema-qualified' => ["select pg_catalog.pg_read_file('/etc/passwd')", 'file read'],
            'read a host file in binary' => ["select pg_read_binary_file('/etc/passwd')", 'file read'],
            'list a directory' => ["select pg_ls_dir('/')", 'directory listing'],
            'stat a file' => ["select pg_stat_file('/etc/passwd')", 'file metadata'],
            'large object import' => ["select lo_import('/etc/passwd')", 'file read'],
            'large object export' => ["select lo_export(1, '/tmp/x')", 'file write'],
            'copy hidden behind a cte' => ["with x as (select 1) copy days to program 'id'", 'command execution'],
            'outbound connection' => ["select * from dblink('host=evil', 'select 1') as t(x int)", 'network'],
            'foreign data wrapper' => ['select * from postgres_fdw_handler()', 'network'],
            'sleep the worker' => ['select pg_sleep(60)', 'denial of service'],
            // The two that would otherwise switch the guard off from inside.
            'turn the timeout off' => ["select set_config('statement_timeout', '0', false)", 'disables the timeout'],
            'turn read-only off' => ["select set_config('default_transaction_read_only', 'off', false)", 'disables read-only'],
            // Takes SQL as a string, so it runs past every entry above.
            'sql inside sql' => ["select query_to_xml('select 1', true, true, '')", 'nested sql'],
            'table dump as xml' => ["select table_to_xml('days', true, true, '')", 'nested sql'],
            'kill another session' => ['select pg_terminate_backend(1)', 'other sessions'],
            'reload the server config' => ['select pg_reload_conf()', 'server state'],
            'read password hashes' => ['select rolname, rolpassword from pg_authid', 'credentials'],
            'read the shadow view' => ['select * from pg_shadow', 'credentials'],
            // Spells pg_read_file without ever writing its letters.
            'unicode-escaped identifier' => ['select U&"\0070g_read_file"(\'/etc/passwd\')', 'obfuscation'],
        ];
    }

    #[DataProvider('forbiddenConstructs')]
    public function test_a_forbidden_construct_is_refused(string $sql, string $why): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('forbidden keyword');

        $this->reader()->run($sql);
    }

    public function test_the_statement_runs_inside_a_read_only_transaction(): void
    {
        // Asking Postgres directly beats inferring it from a failed write:
        // this is the setting itself, read from inside the transaction the
        // guard opened.
        $result = $this->reader()->run("select current_setting('transaction_read_only') as ro");

        $this->assertSame('on', $result['rows'][0]['ro']);
    }

    public function test_the_server_side_timeout_is_in_effect(): void
    {
        // The old SQLite version could only stop a query between rows, so a
        // slow join before the first row ran unbounded. This is the layer
        // that closes it, so it has to be provably set.
        $result = $this->reader(maxSeconds: 7)->run("select current_setting('statement_timeout') as t");

        $this->assertSame('7s', $result['rows'][0]['t']);
    }

    public function test_the_server_cancels_a_query_that_never_returns_a_row(): void
    {
        // A cross join that spends all its time inside the executor: the
        // collection loop never gets a chance to look at its own clock.
        $this->expectException(PDOException::class);

        $this->reader(maxSeconds: 1)->run(
            'select count(*) from generate_series(1, 200000) a, generate_series(1, 200000) b'
        );
    }

    public function test_the_connection_is_usable_again_after_a_failed_query(): void
    {
        // The rollback runs in a finally block. Without it the connection
        // would stay inside a failed transaction and every later query on
        // it, the dashboard's own cards included, would die with "current
        // transaction is aborted".
        try {
            $this->reader()->run('select * from table_that_does_not_exist');
        } catch (PDOException) {
            // expected
        }

        $result = $this->reader()->run('select count(*) as n from days');
        $this->assertSame(40, (int) $result['rows'][0]['n']);
    }

    public function test_the_transaction_does_not_leak_past_the_call(): void
    {
        $this->reader()->run('select 1');

        // Back on the shared connection: had the guard left its transaction
        // open, this would report 'on' rather than 'off'.
        $after = $this->mirror()->selectOne("select current_setting('transaction_read_only') as ro");

        $this->assertSame('off', $after->ro);
    }

    public function test_the_row_cap_truncates_and_says_so(): void
    {
        $result = $this->reader()->run('select * from days', maxRows: 10);

        $this->assertCount(10, $result['rows']);
        $this->assertTrue($result['truncated']);
        $this->assertSame('rows', $result['truncated_by']);
    }

    public function test_a_result_that_exactly_fills_the_cap_is_not_called_truncated(): void
    {
        // Off-by-one here would tell the model its answer is incomplete when
        // it is not, and it would go looking for rows that do not exist.
        $result = $this->reader()->run('select * from days', maxRows: 40);

        $this->assertCount(40, $result['rows']);
        $this->assertFalse($result['truncated']);
    }

    public function test_the_byte_budget_stops_a_wide_result_below_the_row_cap(): void
    {
        $result = $this->reader(maxBytes: 1_000)->run('select * from days', maxRows: 500);

        $this->assertLessThan(40, count($result['rows']));
        $this->assertNotEmpty($result['rows']);
        $this->assertSame('bytes', $result['truncated_by']);
    }

    public function test_one_oversized_row_still_comes_back(): void
    {
        // An empty answer plus "too big" is useless; one row at least shows
        // the shape of what the query found.
        $result = $this->reader(maxBytes: 1)->run('select * from days');

        $this->assertCount(1, $result['rows']);
        $this->assertSame('bytes', $result['truncated_by']);
    }

    public function test_the_clock_stops_collection(): void
    {
        $result = $this->reader(maxSeconds: 0)->run('select * from days');

        $this->assertCount(1, $result['rows']);
        $this->assertSame('time', $result['truncated_by']);
    }

    public function test_an_empty_result_reports_no_columns_and_no_truncation(): void
    {
        $result = $this->reader()->run("select * from days where date = '1999-01-01'");

        $this->assertSame([], $result['columns']);
        $this->assertSame([], $result['rows']);
        $this->assertFalse($result['truncated']);
    }

    public function test_leading_whitespace_does_not_hide_a_write(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->reader()->run("\n\t  delete from days");
    }
}
