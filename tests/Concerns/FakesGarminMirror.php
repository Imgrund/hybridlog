<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Garmin\DataStatus;
use App\Garmin\GarminData;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Full GarminData mock with constructed rows, so feature tests can
 * render the dashboard deterministically instead of depending on the
 * live mirror. Quiet by default; the $today values steer whether the
 * illness pattern fires.
 */
trait FakesGarminMirror
{
    private function dayRow(int $daysAgo, float $rhr): object
    {
        return (object) [
            'date' => now()->subDays($daysAgo)->toDateString(),
            'resting_hr' => $rhr,
            'min_hr' => 42,
            'max_hr' => 150,
            'vo2max_running' => null,
            'calories_total' => 2600,
            'calories_active' => 700,
            'calories_bmr' => 1900,
            'intensity_moderate_min' => 20,
            'intensity_vigorous_min' => 10,
            'stress_avg' => 30,
            'stress_max' => 70,
            'bb_low' => 30,
            'bb_high' => 90,
            'sweat_loss_ml' => null,
        ];
    }

    private function sleepRow(int $daysAgo, float $respiration, int $bedtimeShiftMin = 0): object
    {
        $date = now()->subDays($daysAgo);

        return (object) [
            'date' => $date->toDateString(),
            'score' => 78,
            'duration_s' => 7 * 3600,
            'start_local' => $date->copy()->subDay()->setTime(22, 45)->addMinutes($bedtimeShiftMin)->format('Y-m-d H:i:s'),
            'end_local' => $date->copy()->setTime(6, 30)->format('Y-m-d H:i:s'),
            'deep_s' => 5400,
            'rem_s' => 5900,
            'light_s' => 12000,
            'awake_s' => 900,
            'nap_s' => null,
            'respiration_avg' => $respiration,
            'respiration_lowest' => $respiration - 2,
            'respiration_highest' => $respiration + 3,
            'score_components_json' => null,
        ];
    }

    private function hrvRow(int $daysAgo, float $lastNight, float $weekly = 55.0): object
    {
        return (object) [
            'date' => now()->subDays($daysAgo)->toDateString(),
            'weekly_avg' => $weekly,
            'last_night_avg' => $lastNight,
            'baseline_balanced_low' => 45.0,
            'baseline_balanced_upper' => 65.0,
            'status' => 'BALANCED',
        ];
    }

    /**
     * The optional collections let the companion suites hand in
     * constructed sessions, sets and Garmin load status, and the drift
     * suites their own long series; every other caller keeps the quiet
     * empty mirror.
     *
     * @param  array{rhrToday: float, respToday: float, hrvLastNight: float}  $today
     */
    private function mockGarmin(
        array $today,
        ?Collection $activities = null,
        ?Collection $strengthSets = null,
        ?Collection $trainingStatus = null,
        ?Collection $days = null,
        ?Collection $sleep = null,
        ?Collection $hrv = null,
        ?Collection $bodyComp = null,
    ): void {
        $days ??= collect(range(2, 31))->map(fn (int $i) => $this->dayRow($i, 50.0))
            ->push($this->dayRow(0, $today['rhrToday']))->values();
        $sleep ??= collect(range(2, 31))->map(fn (int $i) => $this->sleepRow($i, 14.0))
            ->push($this->sleepRow(0, $today['respToday']))->values();
        $hrv ??= collect(range(1, 10))->map(fn (int $i) => $this->hrvRow($i, 55.0))
            ->push($this->hrvRow(0, $today['hrvLastNight']))->values();
        $readiness = collect([(object) [
            'date' => now()->toDateString(),
            'score' => 80,
            'current_score' => null,
            'recovery_time_h' => 6,
            'current_recovery_time_h' => null,
            'current_at' => null,
        ]]);

        $this->mock(GarminData::class, function ($mock) use ($days, $sleep, $hrv, $readiness, $activities, $strengthSets, $trainingStatus, $bodyComp) {
            $mock->shouldReceive('days')->andReturn($days);
            $mock->shouldReceive('sleep')->andReturn($sleep);
            $mock->shouldReceive('hrv')->andReturn($hrv);
            $mock->shouldReceive('readiness')->andReturn($readiness);
            $mock->shouldReceive('activities')->andReturn($activities ?? new Collection);
            $mock->shouldReceive('strengthSets')->andReturn($strengthSets ?? new Collection);
            $mock->shouldReceive('trainingStatus')->andReturn($trainingStatus ?? new Collection);
            $mock->shouldReceive('bodyComp')->andReturn($bodyComp ?? new Collection);
            foreach (['weather', 'lappedActivities'] as $empty) {
                $mock->shouldReceive($empty)->andReturn(new Collection);
            }
            $mock->shouldReceive('hasAnyLaps')->andReturnFalse();
            // The range switch asks how far back the mirror reaches before it
            // decides which windows to offer. Answered from the same rows the
            // mock hands out, so the fake cannot contradict itself.
            $mock->shouldReceive('mirrorSpanDays')->andReturn(
                Carbon::parse($days->min('date'))->startOfDay()->diffInDays(now()->startOfDay()) + 1
            );
            $mock->shouldReceive('fitnessAge')->andReturnNull();
            $mock->shouldReceive('heartProfile')->andReturnNull();
            $mock->shouldReceive('latestFetch')->andReturnNull();
            $mock->shouldReceive('fetchProgress')->andReturnNull();
            $mock->shouldReceive('watchLastSync')->andReturnNull();
            // A fresh verdict keeps the header free of staleness notes;
            // these suites test illness/symptoms, not data trust.
            $mock->shouldReceive('dataStatus')
                ->andReturn(DataStatus::evaluate(now()->toIso8601String(), null, null));
            $mock->shouldReceive('isDemo')->andReturnFalse();
        });
    }
}
