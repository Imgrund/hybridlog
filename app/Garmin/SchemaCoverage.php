<?php

declare(strict_types=1);

namespace App\Garmin;

use RuntimeException;
use Throwable;

/**
 * How well each mirrored table is actually filled.
 *
 * The schema alone is misleading: Garmin ships columns this watch never
 * populates (spo2_* in every day because Pulse Ox is off; hr_zones_json
 * was such a column until the fetcher learned to ask for the zones, so
 * older mirrors may still carry a partially filled history). An AI
 * reading only CREATE statements writes a
 * plausible query against them, gets nothing but nulls back and cannot tell
 * an empty column from a quiet week. The call counts as a success, so the
 * usage log never shows it either. Naming the gaps up front is the only
 * place this can be caught.
 */
class SchemaCoverage
{
    /** Below this share of filled rows a column is worth warning about. */
    private const SPARSE_SHARE = 0.05;

    /**
     * Up to this many missing days they are named one by one; beyond it
     * only the count is reported. A near-complete table's few holes are
     * exactly the information (skip 2026-08-12, the watch was off), while
     * a body-composition table that only has rows on weigh-in days would
     * list its entire calendar.
     */
    private const NAMED_GAP_LIMIT = 14;

    /**
     * @param  list<string>  $tables
     * @return array<string, array{rows: int, date_range?: string, days_missing_in_range?: int, missing_dates?: list<string>, never_filled?: list<string>, sparse?: list<string>}>
     */
    public function for(array $tables): array
    {
        $coverage = [];

        foreach ($tables as $table) {
            try {
                $coverage[$table] = $this->table($table);
            } catch (Throwable) {
                // A single unreadable table must not cost the whole schema.
                continue;
            }
        }

        return $coverage;
    }

    /** @return array{rows: int, date_range?: string, never_filled?: list<string>, sparse?: list<string>} */
    private function table(string $table): array
    {
        $connection = Mirror::connection();
        $quoted = '"'.str_replace('"', '""', $table).'"';

        // current_schema() rather than a schema name: the connection is
        // pointed at the acting athlete's mirror, and naming one here
        // would measure every athlete against the first one's tables.
        $columns = array_map(
            fn (object $c): string => (string) $c->column_name,
            $connection->select(
                'select column_name from information_schema.columns '
                .'where table_schema = current_schema() and table_name = ? '
                .'order by ordinal_position',
                [$table],
            ),
        );

        if ($columns === []) {
            // No columns means no such table; reporting it as empty would
            // invent a table the mirror does not have.
            throw new RuntimeException("unknown table {$table}");
        }

        // One aggregate per table rather than one per column: count(col)
        // skips nulls, count(*) does not, so the pair gives the fill share.
        $selects = ['count(*) as __rows'];
        foreach ($columns as $i => $column) {
            $selects[] = sprintf('count("%s") as c%d', str_replace('"', '""', $column), $i);
        }

        $row = (array) $connection->selectOne('select '.implode(', ', $selects).' from '.$quoted);
        $rows = (int) $row['__rows'];

        $result = ['rows' => $rows];

        if ($rows === 0) {
            return $result;
        }

        if (in_array('date', $columns, true)) {
            // Quoted, because `date` is a type name to the Postgres parser
            // as well as a column name here.
            $span = (array) $connection->selectOne("select min(\"date\") as a, max(\"date\") as b from {$quoted}");
            if ($span['a'] !== null) {
                $result['date_range'] = $span['a'].' .. '.$span['b'];
                $result += $this->missingDays($table, $quoted);
            }
        }

        $never = [];
        $sparse = [];

        foreach ($columns as $i => $column) {
            $filled = (int) $row['c'.$i];

            if ($filled === 0) {
                $never[] = $column;
            } elseif ($filled / $rows < self::SPARSE_SHARE) {
                $sparse[] = sprintf('%s (%d/%d)', $column, $filled, $rows);
            }
        }

        if ($never !== []) {
            $result['never_filled'] = $never;
        }
        if ($sparse !== []) {
            $result['sparse'] = $sparse;
        }

        return $result;
    }

    /**
     * Dates inside the covered range that have no row at all.
     *
     * date_range alone hides them: a table spanning 120 days with 115
     * rows reads as complete, and a model that finds nothing for one of
     * the holes concludes the athlete did nothing that day. Naming the
     * gap turns that into "no data", which is the true statement.
     *
     * Only for tables whose primary key is exactly (date). Those are the
     * one-row-per-day tables, where a missing date really is a hole;
     * activities has quiet days by design, and judging it by calendar
     * coverage would flag every rest day.
     *
     * @return array{days_missing_in_range?: int, missing_dates?: list<string>}
     */
    private function missingDays(string $table, string $quoted): array
    {
        if (! $this->keyedByDate($table)) {
            return [];
        }

        $connection = Mirror::connection();

        // The dates are fetcher-written ISO strings, so the cast is safe
        // for the tables that get here; anything unparseable lands in the
        // per-table catch above and costs that table its coverage line.
        $missing = (int) ((array) $connection->selectOne(
            "select (max(\"date\")::date - min(\"date\")::date) + 1 - count(distinct \"date\") as missing from {$quoted}"
        ))['missing'];

        if ($missing <= 0) {
            return [];
        }

        $result = ['days_missing_in_range' => $missing];

        if ($missing <= self::NAMED_GAP_LIMIT) {
            $result['missing_dates'] = array_map(
                fn (object $r): string => (string) $r->day,
                $connection->select(
                    'select to_char(g.d, \'YYYY-MM-DD\') as day '
                    ."from generate_series((select min(\"date\")::date from {$quoted}), (select max(\"date\")::date from {$quoted}), interval '1 day') as g(d) "
                    ."where not exists (select 1 from {$quoted} t where t.\"date\" = to_char(g.d, 'YYYY-MM-DD')) "
                    .'order by g.d desc',
                ),
            );
        }

        return $result;
    }

    /**
     * Whether the table's primary key is exactly the date column.
     *
     * Read from pg_catalog rather than information_schema: this runs as
     * the tenant's reader, and key_column_usage hides constraint columns
     * from a role that holds nothing beyond SELECT, which is exactly all
     * the reader may hold. The catalog answers everyone.
     */
    private function keyedByDate(string $table): bool
    {
        $key = array_map(
            fn (object $c): string => (string) $c->attname,
            Mirror::connection()->select(
                'select a.attname from pg_constraint c '
                .'join pg_class t on t.oid = c.conrelid '
                .'join pg_namespace n on n.oid = t.relnamespace '
                .'join unnest(c.conkey) with ordinality as k(attnum, ord) on true '
                .'join pg_attribute a on a.attrelid = t.oid and a.attnum = k.attnum '
                ."where n.nspname = current_schema() and t.relname = ? and c.contype = 'p' "
                .'order by k.ord',
                [$table],
            ),
        );

        return $key === ['date'];
    }
}
