<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Garmin\Mirror;
use App\Garmin\MirrorSchema;
use App\Garmin\SchemaCoverage;
use App\Mcp\Concerns\ChecksConnectorPermissions;
use App\Mcp\LoggedTool;
use App\Models\ConnectorSettings;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(
    'Describe the Garmin mirror schema: every table with its commented CREATE statement, how well '.
    'it is filled (row count, date range, columns this device never records), plus semantic notes '.
    '(units, enums, quirks). Call this once per conversation before writing SQL. Never build a '.
    'query on a column listed under never_filled: it returns nulls, not a finding.'
)]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class DescribeSchemaTool extends LoggedTool
{
    use ChecksConnectorPermissions;

    public function execute(Request $request, MirrorSchema $schema, SchemaCoverage $coverage): Response
    {
        if ($deny = $this->denyUnless($this->settings()->share_health_data, 'share_health_data')) {
            return $deny;
        }

        $tables = collect($schema->all())
            ->when(! $this->settings()->share_body_metrics, fn ($t) => $t->except(ConnectorSettings::BODY_METRIC_TABLES));

        $filled = $coverage->for($tables->keys()->all());

        return Response::json([
            'dialect' => 'PostgreSQL',
            'tables' => $tables->map(fn (string $sql, string $name): array => [
                'schema' => $sql,
            ] + ($filled[$name] ?? [])),
            'notes' => [
                'never_filled lists columns this device does not record at all. Querying them yields nulls, never an answer.',
                'type_key `hiit` is high-intensity circuit work, a CrossFit WOD as often as anything else; strength/HIIT training load is systematically underestimated without a chest strap.',
                'weight_g and total_volume_g are grams; *_s columns are seconds; dates are `text` in `YYYY-MM-DD`, not the date type: compare them as strings, or cast with `date::date` before using date arithmetic.',
                ...($this->roundShimMissing() ? [
                    'Most measurement columns are double precision, and Postgres has no two-argument round() for that type: write `round((expr)::numeric, 1)`, not `round(expr, 1)`.',
                ] : []),
                'sleep.start_local/end_local are local wall-clock datetimes `YYYY-MM-DD HH:MM:SS`.',
                'strength_sets.exercise_category is mostly UNKNOWN in circuit work (Garmin limitation); per-set weights are almost never present.',
                'hrv.status enum: BALANCED / UNBALANCED / LOW / POOR. readiness.level: HIGH / MODERATE / LOW.',
                'Rows per query are capped at 500; only a single SELECT (or WITH) statement is allowed.',
            ],
        ]);
    }

    /**
     * Whether this mirror still lacks the round(double precision, integer)
     * overload fetcher/schema.sql ships. The warning above is only true --
     * and only worth its tokens -- on a mirror the fetcher has not run
     * against since the overload was added; everywhere else round(expr, 1)
     * simply works, and a note claiming otherwise would talk the model out
     * of writing it. Asked of the mirror rather than assumed, the same way
     * everything else in this answer is.
     */
    private function roundShimMissing(): bool
    {
        return Mirror::connection()->selectOne(
            'select 1 as found from pg_proc p join pg_namespace n on n.oid = p.pronamespace'
            ." where n.nspname = current_schema() and p.proname = 'round'"
        ) === null;
    }
}
