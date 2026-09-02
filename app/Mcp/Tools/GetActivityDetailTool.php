<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Garmin\GarminData;
use App\Mcp\Concerns\ChecksConnectorPermissions;
use App\Mcp\LoggedTool;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(
    'One session in depth: the summary, its heart-rate zones as seconds, minutes and shares next '.
    'to the zone floors the athlete\'s profile carried on that date (hard_minutes is zones 4 and 5 '.
    'together), the laps, the strength sets per exercise category, the heart-rate curve in a few '.
    'dozen buckets, and how the session compares with the earlier ones of the same type: medians, '.
    'the deviation from them, the rank by training load. Without an activity_id it reads the most '.
    'recent session. Use this rather than rebuilding zones from hr_zones_json or the curve from '.
    'intraday in SQL.'
)]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetActivityDetailTool extends LoggedTool
{
    use ChecksConnectorPermissions;

    /** Earlier sessions of the same kind a session is measured against. */
    private const COMPARE_WITH = 6;

    /**
     * The curve is a shape, not a log: thirty points show where the
     * pulse climbed and where it came down, and a sample every two
     * seconds over ninety minutes would be 2,700 rows nobody reads.
     */
    private const CURVE_POINTS = 30;

    /** More laps than this is autolap noise; the race-splits tool reads those. */
    private const LAP_LIST_LIMIT = 30;

    public function schema(JsonSchema $schema): array
    {
        return [
            'activity_id' => $schema->integer()
                ->description('Which activity to read. Omit for the most recent one.'),
            'compare_with' => $schema->integer()
                ->description('How many earlier sessions of the same type_key to compare against (default 6, max 20).'),
            'include_hr_curve' => $schema->boolean()
                ->description('Whether to include the heart-rate curve from the intraday samples (default true).'),
        ];
    }

    public function execute(Request $request, GarminData $garmin): Response
    {
        if ($deny = $this->denyUnless($this->settings()->share_health_data, 'share_health_data')) {
            return $deny;
        }

        $validated = $request->validate([
            'activity_id' => ['nullable', 'integer'],
            'compare_with' => ['nullable', 'integer', 'min:1', 'max:20'],
            'include_hr_curve' => ['nullable', 'boolean'],
        ]);

        $activity = isset($validated['activity_id'])
            ? $garmin->activity((int) $validated['activity_id'])
            : $garmin->latestActivity();

        if ($activity === null) {
            return Response::json([
                'has_data' => false,
                'hint' => isset($validated['activity_id'])
                    ? 'No activity with that id in the mirror. Pick one of the candidates, or find the id with query-health-data-tool.'
                    : 'The mirror holds no activities yet.',
                'candidates' => $this->candidateList($garmin->recentActivities()),
            ]);
        }

        $start = Carbon::parse((string) $activity->start_local);
        $end = $start->copy()->addSeconds((int) round((float) ($activity->duration_s ?? 0)));
        $zones = $this->zones($activity, $garmin->heartProfileOn((string) $activity->date));

        $payload = [
            'has_data' => true,
            'activity' => $this->summary($activity, $end),
            'hr_zones' => $zones,
            'laps' => $this->laps($garmin->activityLaps((int) $activity->id)),
            'strength_sets' => $this->sets($garmin->activitySets((int) $activity->id)),
        ];

        if ($validated['include_hr_curve'] ?? true) {
            $payload['hr_curve'] = $this->curve(
                $garmin->intradayHeartRate($start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')),
                $start,
            );
        }

        $payload['comparison'] = $this->comparison(
            $activity,
            $zones['hard_minutes'] ?? null,
            $garmin->earlierActivitiesOfType(
                (string) $activity->type_key,
                (string) $activity->start_local,
                (int) ($validated['compare_with'] ?? self::COMPARE_WITH),
            ),
        );

        $payload['notes'] = [
            'hr_zones follows the zone model Garmin applied when the session was recorded; the floors shown are the athlete\'s profile on that date, or the nearest snapshot the mirror holds, so a later change of zones does not rewrite history.',
            'hard_minutes counts zones 4 and 5 by that profile: the minutes above the aerobic threshold, which is what a circuit session is usually judged on.',
            'A multisport parent carries the sum of its children\'s zone seconds; its own laps and sets belong to the children.',
            'comparison looks at earlier sessions of the same type_key only. deviation is this session minus the median of those; rank 1 is the highest training_load in the group with this session included.',
            'One session is one data point. Read a trend out of the comparison, not a verdict out of the session.',
        ];

        return Response::json($payload);
    }

    /** @return array<string, mixed> */
    private function summary(object $activity, Carbon $end): array
    {
        $duration = (float) ($activity->duration_s ?? 0);
        $distance = (float) ($activity->distance_m ?? 0);

        return array_filter([
            'id' => (int) $activity->id,
            'date' => $activity->date,
            'start_local' => $activity->start_local,
            'end_local' => $end->format('Y-m-d H:i:s'),
            'type_key' => $activity->type_key,
            'name' => $activity->name,
            'duration_s' => round($duration, 1),
            'moving_s' => $activity->moving_s !== null ? round((float) $activity->moving_s, 1) : null,
            'distance_m' => $distance > 0 ? round($distance, 1) : null,
            // Elapsed time over distance, the same pace the race-splits
            // tool reports per lap, so the two never disagree about a run.
            'pace_s_per_km' => $distance > 0 && $duration > 0 ? (int) round($duration / ($distance / 1000)) : null,
            'avg_hr' => $activity->avg_hr !== null ? (int) $activity->avg_hr : null,
            'max_hr' => $activity->max_hr !== null ? (int) $activity->max_hr : null,
            'calories' => $activity->calories !== null ? (int) $activity->calories : null,
            'aerobic_te' => $activity->aerobic_te !== null ? round((float) $activity->aerobic_te, 1) : null,
            'anaerobic_te' => $activity->anaerobic_te !== null ? round((float) $activity->anaerobic_te, 1) : null,
            'training_load' => $activity->training_load !== null ? round((float) $activity->training_load, 1) : null,
            'elevation_gain_m' => $activity->elevation_gain_m !== null ? round((float) $activity->elevation_gain_m, 1) : null,
            'total_sets' => $activity->total_sets !== null ? (int) $activity->total_sets : null,
            'active_sets' => $activity->active_sets !== null ? (int) $activity->active_sets : null,
            'total_reps' => $activity->total_reps !== null ? (int) $activity->total_reps : null,
            'total_volume_g' => $activity->total_volume_g !== null ? round((float) $activity->total_volume_g) : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Seconds per zone as the fetcher stored them, read out three ways.
     *
     * The column has three states and they mean different things to the
     * athlete: null is a session the fetcher has not asked Garmin about
     * yet (its backfill is capped per run), an empty object is Garmin's
     * own answer that this session has no zones (a session without heart
     * rate, a multisport parent whose children were never read), and a
     * filled object is the answer. Folding the first two into "no data"
     * would send the model looking for a bug that is only a queue.
     *
     * @return array<string, mixed>
     */
    private function zones(object $activity, ?object $profile): array
    {
        $floors = $profile === null ? [] : array_filter([
            'profile_date' => $profile->date,
            'max_hr' => $profile->max_hr !== null ? (int) $profile->max_hr : null,
            'lthr_bpm' => $profile->lthr_bpm !== null ? (int) $profile->lthr_bpm : null,
            'zone1_floor' => $profile->zone1_floor !== null ? (int) $profile->zone1_floor : null,
            'zone2_floor' => $profile->zone2_floor !== null ? (int) $profile->zone2_floor : null,
            'zone3_floor' => $profile->zone3_floor !== null ? (int) $profile->zone3_floor : null,
            'zone4_floor' => $profile->zone4_floor !== null ? (int) $profile->zone4_floor : null,
            'zone5_floor' => $profile->zone5_floor !== null ? (int) $profile->zone5_floor : null,
        ], fn ($v) => $v !== null);

        $withProfile = $floors === [] ? [] : ['profile' => $floors];
        $seconds = self::zoneSeconds($activity);

        if ($seconds === null) {
            return [
                'has_data' => false,
                'hint' => 'The fetcher has not read the zones of this session yet; they arrive through its capped backfill, usually with the next run.',
            ] + $withProfile;
        }

        if ($seconds === []) {
            return [
                'has_data' => false,
                'hint' => 'Garmin has no heart-rate zones for this session: recorded without heart rate, or a multisport parent whose children were never read.',
            ] + $withProfile;
        }

        $total = array_sum($seconds);
        $byZone = [];

        for ($zone = 1; $zone <= 5; $zone++) {
            $inZone = $seconds[$zone] ?? 0.0;
            $byZone['zone'.$zone] = [
                'seconds' => round($inZone, 1),
                'minutes' => round($inZone / 60, 1),
                'share_pct' => $total > 0 ? round($inZone / $total * 100, 1) : 0.0,
            ];
        }

        return [
            'has_data' => true,
            'zones' => $byZone,
            'covered_s' => round($total, 1),
            'hard_minutes' => self::hardMinutes($seconds),
        ] + $withProfile;
    }

    /**
     * Null when the fetcher has not asked yet, an empty array when Garmin
     * answered that there is nothing, seconds keyed by zone number
     * otherwise. Anything unreadable counts as not asked: the raw archive
     * has the payload, and the next run tries the column again.
     *
     * @return array<int, float>|null
     */
    private static function zoneSeconds(object $activity): ?array
    {
        if ($activity->hr_zones_json === null) {
            return null;
        }

        $decoded = json_decode((string) $activity->hr_zones_json, true);

        if (! is_array($decoded)) {
            return null;
        }

        $seconds = [];
        foreach ($decoded as $zone => $inZone) {
            if (is_numeric($zone) && is_numeric($inZone)) {
                $seconds[(int) $zone] = (float) $inZone;
            }
        }

        return $seconds;
    }

    /** @param  array<int, float>  $seconds */
    private static function hardMinutes(array $seconds): float
    {
        return round((($seconds[4] ?? 0.0) + ($seconds[5] ?? 0.0)) / 60, 1);
    }

    /**
     * @param  Collection<int, object>  $laps
     * @return array<string, mixed>
     */
    private function laps(Collection $laps): array
    {
        if ($laps->isEmpty()) {
            return [
                'has_data' => false,
                'hint' => 'No laps in the mirror for this session: recorded without any, or the fetcher\'s capped backfill has not reached it yet.',
            ];
        }

        $result = [
            'has_data' => true,
            'lap_count' => $laps->count(),
            'hint' => 'get-race-splits-tool classifies these laps as running or station work and paces them.',
        ];

        if ($laps->count() <= self::LAP_LIST_LIMIT) {
            $result['laps'] = $laps->map(fn (object $lap): array => array_filter([
                'lap' => (int) $lap->lap_index,
                'duration_s' => round((float) ($lap->duration_s ?? 0), 1),
                'distance_m' => round((float) ($lap->distance_m ?? 0), 1),
                'avg_hr' => $lap->avg_hr !== null ? (int) $lap->avg_hr : null,
            ], fn ($v) => $v !== null))->values()->all();
        }

        return $result;
    }

    /**
     * Sets grouped by Garmin's exercise category. Rest sets are counted
     * and then left out: they carry no reps and no weight, only the
     * seconds between efforts.
     *
     * @param  Collection<int, object>  $sets
     * @return array<string, mixed>
     */
    private function sets(Collection $sets): array
    {
        if ($sets->isEmpty()) {
            return [
                'has_data' => false,
                'hint' => 'No sets recorded for this session.',
            ];
        }

        $active = $sets->filter(fn (object $set): bool => $set->set_type !== 'REST');

        $categories = $active
            ->groupBy(fn (object $set): string => (string) ($set->exercise_category ?? 'UNKNOWN'))
            ->map(function (Collection $group, string $category): array {
                $weighted = $group->filter(fn (object $set): bool => $set->weight_g !== null && (float) $set->weight_g > 0);

                return array_filter([
                    'category' => $category,
                    'sets' => $group->count(),
                    'reps' => (int) $group->sum(fn (object $set): int => (int) ($set->reps ?? 0)),
                    // Kilograms only where the watch really carried a
                    // weight; a category without one has no top weight,
                    // not a top weight of zero.
                    'top_weight_kg' => $weighted->isEmpty()
                        ? null
                        : round((float) $weighted->max(fn (object $set): float => (float) $set->weight_g) / 1000, 1),
                    'weighted_sets' => $weighted->isEmpty() ? null : $weighted->count(),
                    'duration_s' => round((float) $group->sum(fn (object $set): float => (float) ($set->duration_s ?? 0)), 1),
                ], fn ($v) => $v !== null);
            })
            ->sortByDesc('sets')
            ->values()
            ->all();

        return [
            'has_data' => true,
            'active_sets' => $active->count(),
            'rest_sets' => $sets->count() - $active->count(),
            'categories' => $categories,
            'hint' => 'Circuit work rarely carries a weight on the watch, so sets and reps are the honest volume here; get-strength-progress-tool reads the same sets week by week.',
        ];
    }

    /**
     * The heart-rate curve in buckets of whole minutes, wide enough that
     * the whole session fits into about thirty points.
     *
     * @param  Collection<int, object>  $samples
     * @return array<string, mixed>
     */
    private function curve(Collection $samples, Carbon $start): array
    {
        if ($samples->isEmpty()) {
            return [
                'has_data' => false,
                'hint' => 'No intraday heart-rate samples fall inside this session: the watch may not have uploaded them yet, or the session was recorded by another device.',
            ];
        }

        $startTs = $start->getTimestamp();
        $lastTs = Carbon::parse((string) $samples->last()->ts_local)->getTimestamp();
        $spanMinutes = max(1, (int) ceil(($lastTs - $startTs) / 60));
        $width = max(1, (int) ceil($spanMinutes / self::CURVE_POINTS));

        $buckets = [];
        $peakHr = null;
        $peakAt = null;

        foreach ($samples as $sample) {
            $hr = (int) $sample->heart_rate;
            $minute = intdiv(Carbon::parse((string) $sample->ts_local)->getTimestamp() - $startTs, 60);
            $bucket = intdiv($minute, $width) * $width;

            $buckets[$bucket] ??= ['t_min' => $bucket, 'sum' => 0, 'n' => 0, 'max_hr' => 0];
            $buckets[$bucket]['sum'] += $hr;
            $buckets[$bucket]['n']++;
            $buckets[$bucket]['max_hr'] = max($buckets[$bucket]['max_hr'], $hr);

            if ($peakHr === null || $hr > $peakHr) {
                $peakHr = $hr;
                $peakAt = $minute;
            }
        }

        ksort($buckets);

        return [
            'has_data' => true,
            'samples' => $samples->count(),
            'bucket_minutes' => $width,
            'points' => array_values(array_map(fn (array $b): array => [
                't_min' => $b['t_min'],
                'avg_hr' => (int) round($b['sum'] / $b['n']),
                'max_hr' => $b['max_hr'],
            ], $buckets)),
            'peak_hr' => $peakHr,
            'peak_at_min' => $peakAt,
        ];
    }

    /**
     * The session against the earlier ones of its kind: what a chat
     * otherwise answers with a GROUP BY it has to write anew every time.
     *
     * @param  Collection<int, object>  $earlier
     * @return array<string, mixed>
     */
    private function comparison(object $activity, ?float $hardMinutes, Collection $earlier): array
    {
        if ($earlier->isEmpty()) {
            return [
                'has_data' => false,
                'hint' => 'No earlier session of type '.$activity->type_key.' in the mirror to compare with.',
            ];
        }

        $sessions = $earlier->map(function (object $a): array {
            $seconds = self::zoneSeconds($a);

            return array_filter([
                'id' => (int) $a->id,
                'date' => $a->date,
                'name' => $a->name,
                'duration_s' => round((float) ($a->duration_s ?? 0), 1),
                'avg_hr' => $a->avg_hr !== null ? (int) $a->avg_hr : null,
                'max_hr' => $a->max_hr !== null ? (int) $a->max_hr : null,
                'training_load' => $a->training_load !== null ? round((float) $a->training_load, 1) : null,
                'hard_minutes' => $seconds === null || $seconds === [] ? null : self::hardMinutes($seconds),
            ], fn ($v) => $v !== null);
        });

        $own = [
            'duration_s' => round((float) ($activity->duration_s ?? 0), 1),
            'avg_hr' => $activity->avg_hr !== null ? (float) $activity->avg_hr : null,
            'training_load' => $activity->training_load !== null ? round((float) $activity->training_load, 1) : null,
            'hard_minutes' => $hardMinutes,
        ];

        $medians = [];
        $deviation = [];

        foreach (array_keys($own) as $metric) {
            $values = $sessions->pluck($metric)->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v)->values()->all();

            if ($values === []) {
                continue;
            }

            $median = self::median($values);
            $medians[$metric] = round($median, 1);

            if ($own[$metric] !== null) {
                $deviation[$metric] = array_filter([
                    'absolute' => round($own[$metric] - $median, 1),
                    'pct' => $median > 0 ? round(($own[$metric] - $median) / $median * 100, 1) : null,
                ], fn ($v) => $v !== null);
            }
        }

        $rank = null;

        if ($own['training_load'] !== null) {
            $loads = $sessions->pluck('training_load')->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v);

            $rank = [
                'load_rank' => $loads->filter(fn (float $load): bool => $load > $own['training_load'])->count() + 1,
                'of' => $loads->count() + 1,
            ];
        }

        return array_filter([
            'has_data' => true,
            'type_key' => $activity->type_key,
            'sessions' => $sessions->values()->all(),
            'median' => $medians,
            'deviation' => $deviation,
            'rank' => $rank,
        ], fn ($v) => $v !== null);
    }

    /** @param  list<float>  $values */
    private static function median(array $values): float
    {
        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 0
            ? ($values[$middle - 1] + $values[$middle]) / 2
            : $values[$middle];
    }

    /**
     * The newest sessions, so a caller with a wrong id can name a right
     * one without a second query.
     *
     * @param  Collection<int, object>  $activities
     * @return list<array<string, mixed>>
     */
    private function candidateList(Collection $activities): array
    {
        return $activities->map(fn (object $a): array => [
            'id' => (int) $a->id,
            'date' => $a->date,
            'name' => $a->name,
            'type_key' => $a->type_key,
        ])->values()->all();
    }
}
