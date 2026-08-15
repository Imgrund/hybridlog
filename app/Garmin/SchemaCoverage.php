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
     * @param  list<string>  $tables
     * @return array<string, array{rows: int, date_range?: string, never_filled?: list<string>, sparse?: list<string>}>
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
}
