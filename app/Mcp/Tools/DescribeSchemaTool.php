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
    'it is filled (row count, date range, missing days, columns this device never records), plus '.
    'open fetch errors and semantic notes (units, enums, quirks). Call this once per conversation '.
    'before writing SQL. Never build a query on a column listed under never_filled: it returns '.
    'nulls, not a finding.'
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
        $fetchErrors = $this->openFetchErrors();

        return Response::json([
            'dialect' => 'PostgreSQL',
            'tables' => $tables->map(fn (string $sql, string $name): array => [
                'schema' => $sql,
            ] + ($filled[$name] ?? [])),
            ...($fetchErrors !== null ? ['fetch_errors' => $fetchErrors] : []),
            'notes' => [
                'never_filled lists columns this device does not record at all. Querying them yields nulls, never an answer.',
                'days_missing_in_range counts dates inside a daily table\'s covered range that have no row: nothing was recorded or fetched for them, which is never proof that nothing happened. The dates are listed as missing_dates when there are few enough to name.',
                ...($fetchErrors !== null ? [
                    'fetch_errors lists day/endpoint fetches that failed and were never fetched successfully afterwards. Treat those days as unknown rather than empty.',
                ] : []),
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
     * Fetches that failed and were never made good.
     *
     * fetch_log is upserted on (date, kind), so a later successful fetch
     * of the same day and endpoint overwrites its failure row: whatever
     * still stands with ok = 0 is a hole nobody has closed. Ten most
     * recent by day, because the recent ones are the days a conversation
     * is usually about; the count says how much older history is also
     * affected. Null rather than an empty section when there is nothing
     * to report, so the happy path costs no tokens.
     *
     * @return array{open: int, recent: list<array{date: string, kind: string, error: ?string}>}|null
     */
    private function openFetchErrors(): ?array
    {
        try {
            $connection = Mirror::connection();

            $open = (int) ((array) $connection->selectOne('select count(*) as n from fetch_log where ok = 0'))['n'];

            if ($open === 0) {
                return null;
            }

            return [
                'open' => $open,
                'recent' => array_map(
                    fn (object $r): array => [
                        'date' => (string) $r->date,
                        'kind' => (string) $r->kind,
                        'error' => $r->error === null ? null : mb_substr((string) $r->error, 0, 200),
                    ],
                    $connection->select('select "date", kind, error from fetch_log where ok = 0 order by "date" desc, kind limit 10'),
                ),
            ];
        } catch (\Throwable) {
            // A mirror without the bookkeeping table still has a schema
            // worth describing.
            return null;
        }
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
