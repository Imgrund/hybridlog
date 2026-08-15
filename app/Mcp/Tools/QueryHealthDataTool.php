<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

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
            return Response::error('Query failed: '.$e->getMessage().self::dialectHint($e));
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
}
