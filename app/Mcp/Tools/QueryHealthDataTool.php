<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Garmin\Mirror;
use App\Garmin\QueryTables;
use App\Garmin\ReadOnlyGarminQuery;
use App\Mcp\Concerns\ChecksConnectorPermissions;
use App\Mcp\LoggedTool;
use App\Models\ConnectorSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use PDOException;

#[Description(
    'Run a single read-only SELECT (or WITH ... SELECT) against the Garmin mirror and get the '.
    'rows back (max 500). PostgreSQL dialect. Use describe-schema first for table and column names.'
)]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class QueryHealthDataTool extends LoggedTool
{
    use ChecksConnectorPermissions;

    public function schema(JsonSchema $schema): array
    {
        return [
            'sql' => $schema->string()
                ->description('A single SELECT or WITH...SELECT statement, PostgreSQL dialect.')
                ->required(),
        ];
    }

    public function execute(Request $request, ReadOnlyGarminQuery $query, QueryTables $tables): Response
    {
        if ($deny = $this->denyUnless($this->settings()->share_health_data, 'share_health_data')) {
            return $deny;
        }

        $validated = $request->validate(['sql' => ['required', 'string']]);

        try {
            // Guard first: QueryTables sends the statement through EXPLAIN,
            // and EXPLAIN executes whatever functions the planner needs, so
            // only a vetted statement may go there.
            $sql = $query->guard($validated['sql']);

            if (! $this->settings()->share_body_metrics
                && array_intersect($tables->for($sql), ConnectorSettings::BODY_METRIC_TABLES) !== []) {
                return $this->denyUnless(false, 'share_body_metrics');
            }

            return Response::json($query->run($sql));
        } catch (InvalidArgumentException|PDOException $e) {
            return Response::error('Query failed: '.$e->getMessage().self::dialectHint($e).$this->columnHint($e));
        }
    }

    /**
     * The way out, appended where the model actually reads.
     *
     * SQLSTATE 42883 (undefined function) is nearly always the same two
     * habits meeting this mirror: round(x, n) on a double precision
     * column, which Postgres only defines for numeric, and SQLite's date
     * helpers, which do not exist here. Postgres' own hint says "add
     * explicit type casts" without saying which, and a model that has to
     * guess burns a round trip on it; the schema notes warn ahead of
     * time, but nothing forces a caller through them.
     */
    private static function dialectHint(\Throwable $e): string
    {
        if (! str_contains($e->getMessage(), '42883')) {
            return '';
        }

        return ' Note: the mirror speaks PostgreSQL. round() with two arguments needs numeric, '
            .'so write round((expr)::numeric, 1); SQLite helpers like strftime() or julianday() '
            .'do not exist here.';
    }

    /**
     * The same, for SQLSTATE 42703 (undefined column), which is nearly
     * always a column the model remembered rather than read: a plausible
     * name against the right table. Postgres offers its own "Perhaps you
     * meant" only when the name is an edit or two away, and an invented
     * name rarely is, so without help the retry is another guess. The
     * mirror knows every column that does exist; naming the closest ones
     * turns the retry into a lookup.
     *
     * Ranked by shared snake_case tokens rather than edit distance alone,
     * because invented names recombine real fragments: the distance from
     * sleep_factor_feedback to sleep_score_factor is large, the overlap
     * obvious. Tables the athlete has switched off stay out of the list,
     * exactly as describe-schema hides them.
     */
    private function columnHint(\Throwable $e): string
    {
        if (! str_contains($e->getMessage(), '42703')
            || ! preg_match('/column (?:"?\w+"?\.)?"?(\w+)"? does not exist/', $e->getMessage(), $matches)) {
            return '';
        }

        try {
            $columns = Mirror::connection()->select(
                'select c.relname as table_name, a.attname as column_name'
                .' from pg_class c'
                .' join pg_namespace n on n.oid = c.relnamespace'
                .' join pg_attribute a on a.attrelid = c.oid and a.attnum > 0 and not a.attisdropped'
                ." where n.nspname = current_schema() and c.relkind = 'r'"
            );
        } catch (\Throwable) {
            // The hint must never turn one failure into two.
            return '';
        }

        $missing = strtolower($matches[1]);
        $hidden = $this->settings()->share_body_metrics ? [] : ConnectorSettings::BODY_METRIC_TABLES;

        $scored = [];
        foreach ($columns as $column) {
            if (in_array((string) $column->table_name, $hidden, true)) {
                continue;
            }

            $shared = count(array_intersect(
                array_unique(explode('_', $missing)),
                array_unique(explode('_', (string) $column->column_name)),
            ));

            if ($shared > 0) {
                $scored[] = [
                    'name' => $column->table_name.'.'.$column->column_name,
                    'shared' => $shared,
                    'distance' => levenshtein($missing, (string) $column->column_name),
                ];
            }
        }

        usort($scored, fn (array $a, array $b): int => [$b['shared'], $a['distance']] <=> [$a['shared'], $b['distance']]);

        $closest = array_column(array_slice($scored, 0, 3), 'name');

        return $closest === []
            ? sprintf(' Note: no column named "%s" exists in this mirror; describe-schema lists every table and column.', $missing)
            : sprintf(' Note: no column named "%s" exists; the closest that do: %s. describe-schema lists them all.', $missing, implode(', ', $closest));
    }
}
