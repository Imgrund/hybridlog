<?php

declare(strict_types=1);

namespace App\Garmin;

/**
 * The mirror's tables as commented CREATE statements, rebuilt from the catalog.
 *
 * SQLite kept the text of every CREATE statement it was given, comments and
 * all, so describing the schema to a language model was one select away. That
 * text was carrying real weight: "weight_g double precision" says nothing,
 * "-- tonnage in grams (Garmin unit)" is the difference between an answer and
 * a number that is off by a thousand.
 *
 * Postgres stores no DDL text, so the comments live in the database itself as
 * COMMENT ON COLUMN (see fetcher/schema.sql) and this class renders them back
 * into the shape they came from. The upside over the old arrangement is that
 * psql's \d+ and every other tool now show them too, and that what a model
 * reads is the schema that exists rather than the file someone last edited.
 */
class MirrorSchema
{
    /**
     * @return array<string, string> table name => CREATE statement, name-ordered
     */
    public function all(): array
    {
        $connection = Mirror::connection();

        // pg_catalog rather than information_schema.columns for the columns:
        // attnum is both the sort key and the key col_description takes, so
        // there is no way for a comment to end up on the wrong column. The
        // information_schema equivalent, ordinal_position, skips dropped
        // columns while attnum does not, and the two drift apart silently
        // the first time anyone drops one.
        //
        // current_schema() keeps this pointed at whatever the connection's
        // search_path says, which is what makes a test mirror work.
        $columns = $connection->select(
            'select c.relname as table_name,'
            .' obj_description(c.oid) as table_comment,'
            .' a.attname as column_name,'
            .' format_type(a.atttypid, a.atttypmod) as data_type,'
            .' col_description(c.oid, a.attnum) as comment'
            .' from pg_class c'
            .' join pg_namespace n on n.oid = c.relnamespace'
            .' join pg_attribute a on a.attrelid = c.oid and a.attnum > 0 and not a.attisdropped'
            ." where n.nspname = current_schema() and c.relkind = 'r'"
            .' order by c.relname, a.attnum'
        );

        // pg_catalog here too, and for a second reason on top of the one
        // above: information_schema.key_column_usage only shows constraints
        // on tables the current user owns. This runs as the tenant's reader
        // role, which owns nothing, so the standard view returns an empty
        // set and every CREATE statement would quietly lose its primary key.
        // The catalog answers whoever asks. conkey carries the columns in
        // key order, which unnest WITH ORDINALITY keeps.
        $keys = $this->primaryKeys($connection->select(
            'select c.relname as table_name, a.attname as column_name'
            .' from pg_constraint con'
            .' join pg_class c on c.oid = con.conrelid'
            .' join pg_namespace n on n.oid = c.relnamespace'
            .' join lateral unnest(con.conkey) with ordinality as k(attnum, ord) on true'
            .' join pg_attribute a on a.attrelid = c.oid and a.attnum = k.attnum'
            ." where n.nspname = current_schema() and con.contype = 'p'"
            .' order by c.relname, k.ord'
        ));

        $tables = [];
        foreach ($columns as $column) {
            $tables[(string) $column->table_name][] = $column;
        }

        $rendered = [];
        foreach ($tables as $table => $definition) {
            $rendered[$table] = $this->render($table, $definition, $keys[$table] ?? []);
        }

        return $rendered;
    }

    /**
     * @param  list<object>  $rows
     * @return array<string, list<string>>
     */
    private function primaryKeys(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            $keys[(string) $row->table_name][] = (string) $row->column_name;
        }

        return $keys;
    }

    /**
     * @param  list<object>  $columns
     * @param  list<string>  $primaryKey
     */
    private function render(string $table, array $columns, array $primaryKey): string
    {
        /** @var list<array{string, ?string}> $body */
        $body = [];

        foreach ($columns as $column) {
            $body[] = [
                sprintf('    %s %s', $column->column_name, $column->data_type),
                $column->comment === null ? null : (string) $column->comment,
            ];
        }

        if ($primaryKey !== []) {
            $body[] = ['    PRIMARY KEY ('.implode(', ', $primaryKey).')', null];
        }

        // Trailing comments line up one space past the longest definition,
        // counting the comma that all but the last line carry.
        $width = max(array_map(fn (array $entry): int => strlen($entry[0]), $body)) + 1;

        $lines = [];

        // A comment on the table itself goes above the statement, the way it
        // would be written by hand. These carry the things no column can say:
        // that heart_profile's newest row is the current one, for instance.
        if ($columns[0]->table_comment !== null) {
            foreach (explode("\n", (string) $columns[0]->table_comment) as $paragraph) {
                $lines[] = '-- '.trim($paragraph);
            }
        }

        $lines[] = "CREATE TABLE {$table} (";
        $last = count($body) - 1;

        foreach ($body as $i => [$definition, $comment]) {
            $line = $definition.($i === $last ? '' : ',');

            if ($comment === null) {
                $lines[] = $line;
            } elseif (str_contains($comment, "\n")) {
                // A comment that needed several lines in the schema file gets
                // them back, above the column instead of beside it.
                foreach (explode("\n", $comment) as $paragraph) {
                    $lines[] = '    -- '.trim($paragraph);
                }
                $lines[] = $line;
            } else {
                $lines[] = str_pad($line, $width).' -- '.$comment;
            }
        }

        $lines[] = ');';

        return implode("\n", $lines);
    }
}
