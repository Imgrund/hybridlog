<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Garmin\GarminData;
use App\Garmin\Stimulus;
use App\Garmin\TrainingLoad;
use App\Mcp\Concerns\ChecksConnectorPermissions;
use App\Mcp\LoggedTool;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(
    'The fitness/fatigue/form model as the dashboard computes it: CTL (42-day EWMA), ATL (7-day), '.
    'TSB, today\'s acute:chronic workload ratio with its corridor verdict, plus the weekly load '.
    'split by stimulus (run / strength / other). Call this instead of re-deriving CTL, ATL or the '.
    'ratio in SQL: the EWMAs start where the athlete\'s history starts, and an ad-hoc SQL version '.
    'will disagree with the dashboard\'s numbers.'
)]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetTrainingLoadTool extends LoggedTool
{
    use ChecksConnectorPermissions;

    /** ISO weeks the stimulus split reports. */
    private const STIMULUS_WEEKS = 4;

    public function schema(JsonSchema $schema): array
    {
        return [
            'series_days' => $schema->integer()
                ->description('How many trailing days of the CTL/ATL/TSB series to return (default 28, max 120). Today\'s values are always included and never depend on this window.'),
        ];
    }

    public function execute(Request $request, GarminData $garmin, TrainingLoad $model): Response
    {
        if ($deny = $this->denyUnless($this->settings()->share_health_data, 'share_health_data')) {
            return $deny;
        }

        $validated = $request->validate(['series_days' => ['nullable', 'integer', 'min:7', 'max:120']]);
        $seriesDays = (int) ($validated['series_days'] ?? 28);

        // The same rows the dashboard render feeds the model (400 days of
        // activities, 120 days of training status). The EWMAs warm up from
        // the first recorded day regardless of the requested window, so
        // today's values here and on the dashboard are the same numbers.
        $activities = $garmin->activities(400);
        $series = $model->series($activities, $seriesDays);

        if ($series['dates'] === []) {
            return Response::json([
                'has_data' => false,
                'hint' => 'No activities with a training load in the mirror yet, so there is no model to read.',
            ]);
        }

        $acwr = $model->acwr($garmin->trainingStatus(120), $series['dailyLoad']);
        $status = TrainingLoad::acwrStatus($acwr['value']);

        $modelStart = $series['modelStart'];
        $warmupUntil = $modelStart !== null
            ? CarbonImmutable::parse($modelStart)->addDays((int) TrainingLoad::CTL_TC)->toDateString()
            : null;

        return Response::json([
            'has_data' => true,
            'current' => [
                'date' => end($series['dates']),
                'ctl_fitness' => end($series['ctl']),
                'atl_fatigue' => end($series['atl']),
                'tsb_form' => end($series['tsb']),
                'acwr' => $acwr + [
                    'status' => $status,
                    'status_word' => TrainingLoad::acwrWords()[$status],
                    'corridor' => 'detraining < 0.8, good 0.8-1.3, warning 1.3-1.5, critical > 1.5',
                ],
            ],
            'model' => array_filter([
                'ctl_time_constant_days' => (int) TrainingLoad::CTL_TC,
                'atl_time_constant_days' => 7,
                'model_start' => $modelStart,
                // An EWMA started from zero reaches only 63 % of its
                // sustained level after one time constant, so CTL readings
                // before this date describe the model filling up, not the
                // athlete detraining.
                'warmup_until' => $warmupUntil,
            ], fn ($v) => $v !== null),
            'series' => [
                'dates' => $series['dates'],
                'ctl' => $series['ctl'],
                'atl' => $series['atl'],
                'tsb' => $series['tsb'],
            ],
            'stimulus_weeks' => $this->stimulusWeeks($activities),
            'notes' => [
                'TSB (form) is yesterday\'s CTL minus yesterday\'s ATL: positive means fresher than fit, strongly negative means a fatigue block.',
                'acwr.source=garmin means Garmin\'s own acute/chronic values; computed means a 7-day sum against the 28-day weekly average of per-activity load.',
                'stimulus_weeks sums Garmin training load per ISO week; hiit counts as strength (circuit work loads muscle, not endurance), and the running week is still growing.',
            ],
        ]);
    }

    /**
     * Training load per stimulus bucket over the last ISO weeks, newest
     * last. The buckets are Stimulus::bucket(), the same mapping the
     * weekly load card uses, so chat and chart cannot disagree about what
     * counted as running.
     *
     * @param  Collection<int, object>  $activities
     * @return list<array<string, mixed>>
     */
    private function stimulusWeeks($activities): array
    {
        $since = now()->startOfWeek()->subWeeks(self::STIMULUS_WEEKS - 1);

        $weeks = [];
        foreach ($activities as $activity) {
            if ($activity->date === null || $activity->date < $since->toDateString()) {
                continue;
            }
            $week = date('o-\WW', strtotime($activity->date));
            $bucket = Stimulus::bucket($activity->type_key);
            $weeks[$week][$bucket] = ($weeks[$week][$bucket] ?? 0.0) + (float) ($activity->training_load ?? 0);
            $weeks[$week]['total'] = ($weeks[$week]['total'] ?? 0.0) + (float) ($activity->training_load ?? 0);
        }
        ksort($weeks);

        return collect($weeks)
            ->map(fn (array $buckets, string $week) => ['week' => $week] + array_map(fn ($v) => round($v), $buckets))
            ->values()
            ->all();
    }
}
