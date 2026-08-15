<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Garmin\GarminData;
use App\Mcp\Concerns\ChecksConnectorPermissions;
use App\Mcp\LoggedTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(
    'Lap-by-lap breakdown of one race-style session: every lap classified as running or station '.
    'work, pace per running lap, how far the pace drifted from the first lap to the last, and the '.
    'time the clock ran while nobody moved. Built for sessions that alternate between the two, '.
    'which is what a HYROX race or simulation is (8 x 1 km with a station between each), but it '.
    'reads any activity carrying laps: an interval session, a multi-sport day, a road race with '.
    'manual splits. Without an activity_id it takes the most recent session that has enough laps '.
    'to be worth splitting.'
)]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetRaceSplitsTool extends LoggedTool
{
    use ChecksConnectorPermissions;

    /**
     * Laps below this count are not a race, they are a recording. Every
     * A circuit session closes with one lap and most runs with a handful of autolaps;
     * four is the floor where alternating work starts to show a shape.
     */
    private const MIN_LAPS = 4;

    public function schema(JsonSchema $schema): array
    {
        return [
            'activity_id' => $schema->integer()
                ->description('Which activity to split. Omit for the most recent one carrying at least min_laps laps.'),
            'min_laps' => $schema->integer()
                ->description('How many laps an activity needs before it counts as race-style when picking one (default 4). Ignored when activity_id is given.'),
        ];
    }

    public function execute(Request $request, GarminData $garmin): Response
    {
        if ($deny = $this->denyUnless($this->settings()->share_health_data, 'share_health_data')) {
            return $deny;
        }

        $validated = $request->validate([
            'activity_id' => ['nullable', 'integer'],
            'min_laps' => ['nullable', 'integer', 'min:2', 'max:60'],
        ]);

        $minLaps = (int) ($validated['min_laps'] ?? self::MIN_LAPS);

        // No laps anywhere is a different answer from no laps on this
        // activity: the first means the fetcher has not backfilled splits
        // yet, the second means this session was recorded without them.
        if (! $garmin->hasAnyLaps()) {
            return Response::json([
                'has_data' => false,
                'hint' => 'The mirror carries no lap data at all yet. Splits arrive through the fetcher\'s capped backfill, so a recent race may take a few runs to show up.',
            ]);
        }

        $candidates = $garmin->lappedActivities($minLaps);
        $activity = isset($validated['activity_id'])
            ? $candidates->firstWhere('id', $validated['activity_id'])
            : $candidates->first();

        // An id the picker does not list is still worth honouring: the
        // caller may have read it out of query-health-data-tool, where
        // no lap floor applies.
        if ($activity === null && isset($validated['activity_id'])) {
            $activity = $garmin->lappedActivities(1, 400)->firstWhere('id', $validated['activity_id']);
        }

        if ($activity === null) {
            return Response::json([
                'has_data' => false,
                'hint' => isset($validated['activity_id'])
                    ? 'That activity has no laps in the mirror.'
                    : "No activity with at least {$minLaps} laps. Lower min_laps, or record the next race with the lap button.",
                'candidates' => $this->candidateList($garmin->lappedActivities(2)),
            ]);
        }

        $laps = $garmin->activityLaps((int) $activity->id);

        if ($laps->isEmpty()) {
            return Response::json([
                'has_data' => false,
                'hint' => 'That activity has no laps in the mirror.',
            ]);
        }

        $segments = $this->segments($laps);
        $running = $segments->where('kind', 'run');

        return Response::json([
            'has_data' => true,
            'activity' => [
                'id' => (int) $activity->id,
                'date' => $activity->date,
                'start_local' => $activity->start_local,
                'name' => $activity->name,
                'type_key' => $activity->type_key,
                'lap_count' => $laps->count(),
            ],
            'segments' => $segments->all(),
            'totals' => $this->totals($segments),
            'running' => $this->running($running),
            'other_candidates' => $this->candidateList(
                $garmin->lappedActivities($minLaps)->where('id', '!=', $activity->id)
            ),
            'notes' => [
                'kind is read off the lap itself: a lap that covered distance is running, a lap that covered none is station work. Garmin records the station as distance 0.0, which is a measurement, not a gap.',
                'duration_s is elapsed time, the one a race is scored on. moving_s is time in motion, and the difference between them is the clock running while the athlete stood still.',
                'HYROX: the eight stations are SkiErg, sled push, sled pull, burpee broad jumps, row, farmers carry, sandbag lunges and wall balls, each after a 1 km run. Whether the transitions (the roxzone) appear as laps of their own depends on the recording: pressing lap at every roxzone entry and exit puts them in this list as short station laps, otherwise they sit inside the running laps and show up as non_moving_s there.',
                'pace_drift_pct compares the last running lap with the first. Positive means the later laps were slower, which is what running on tired legs costs; the number is descriptive, not a verdict.',
                'A single session is one data point. Ask for two races and compare them rather than reading a trend into one.',
            ],
        ]);
    }

    /**
     * One row per lap, classified and with pace where pace means
     * something. Station laps carry no pace: dividing a wall-ball set by
     * the metres it did not cover would invent a number.
     *
     * @param  Collection<int, object>  $laps
     * @return Collection<int, array<string, mixed>>
     */
    private function segments(Collection $laps): Collection
    {
        return $laps->map(function (object $lap): array {
            $distance = (float) ($lap->distance_m ?? 0);
            $duration = (float) ($lap->duration_s ?? 0);
            $moving = (float) ($lap->moving_s ?? 0);
            $isRun = $distance > 0;

            return array_filter([
                'lap' => (int) $lap->lap_index,
                'kind' => $isRun ? 'run' : 'station',
                'duration_s' => round($duration, 1),
                'moving_s' => round($moving, 1),
                'non_moving_s' => round(max(0.0, $duration - $moving), 1),
                'distance_m' => $isRun ? round($distance, 1) : null,
                'pace_s_per_km' => $isRun && $distance > 0 ? (int) round($duration / ($distance / 1000)) : null,
                'avg_hr' => $lap->avg_hr !== null ? (int) $lap->avg_hr : null,
                'max_hr' => $lap->max_hr !== null ? (int) $lap->max_hr : null,
            ], fn ($v) => $v !== null);
        });
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $segments
     * @return array<string, mixed>
     */
    private function totals(Collection $segments): array
    {
        $run = $segments->where('kind', 'run');
        $station = $segments->where('kind', 'station');
        $elapsed = (float) $segments->sum('duration_s');

        return array_filter([
            'elapsed_s' => round($elapsed, 1),
            'run_s' => round((float) $run->sum('duration_s'), 1),
            'station_s' => round((float) $station->sum('duration_s'), 1),
            'non_moving_s' => round((float) $segments->sum('non_moving_s'), 1),
            'run_distance_m' => round((float) $run->sum('distance_m'), 1),
            'run_laps' => $run->count(),
            'station_laps' => $station->count(),
            // The split every hybrid race is won and lost on, and the one
            // number a plain activity summary cannot show.
            'run_share_pct' => $elapsed > 0 ? round((float) $run->sum('duration_s') / $elapsed * 100, 1) : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * How the running held up. Absent when a session has fewer than two
     * running laps: drift needs something to drift from.
     *
     * @param  Collection<int, array<string, mixed>>  $running
     * @return array<string, mixed>
     */
    private function running(Collection $running): array
    {
        $paces = $running->pluck('pace_s_per_km')->filter()->values();

        if ($paces->count() < 2) {
            return ['laps' => $running->count()];
        }

        $first = (int) $paces->first();
        $last = (int) $paces->last();

        return [
            'laps' => $running->count(),
            'first_lap_pace_s_per_km' => $first,
            'last_lap_pace_s_per_km' => $last,
            'fastest_lap_pace_s_per_km' => (int) $paces->min(),
            'slowest_lap_pace_s_per_km' => (int) $paces->max(),
            'mean_pace_s_per_km' => (int) round($paces->avg()),
            'pace_drift_pct' => round(($last - $first) / $first * 100, 1),
        ];
    }

    /**
     * The picker's shortlist, so a caller who wanted a different session
     * can name one without a second query.
     *
     * @param  Collection<int, object>  $activities
     * @return list<array<string, mixed>>
     */
    private function candidateList(Collection $activities): array
    {
        return $activities->take(5)->map(fn (object $a): array => [
            'id' => (int) $a->id,
            'date' => $a->date,
            'name' => $a->name,
            'type_key' => $a->type_key,
            'lap_count' => (int) $a->lap_count,
        ])->values()->all();
    }
}
