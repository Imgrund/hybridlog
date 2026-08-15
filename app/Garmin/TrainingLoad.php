<?php

namespace App\Garmin;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Fitness/fatigue/form model (CTL/ATL/TSB) over Garmin's per-activity
 * training load, plus the acute:chronic workload ratio.
 *
 * CTL = 42-day EWMA of daily load, ATL = 7-day EWMA,
 * TSB = yesterday's CTL minus yesterday's ATL (classic PMC definition).
 */
class TrainingLoad
{
    /**
     * Exponential time constants in days. CTL_TC is public because the
     * display needs it: an EWMA started from zero reaches only 63 % of
     * its sustained level after one time constant, so the caller has to
     * know where the model stops warming up and starts measuring.
     */
    public const CTL_TC = 42.0;

    private const ATL_TC = 7.0;

    /**
     * `modelStart` is the first day the model saw any load. Everything
     * before it is a zero the athlete never lived, and everything within
     * one CTL time constant after it is still the model filling up.
     *
     * @param  Collection  $activities  rows with ->date and ->training_load
     * @return array{dates: string[], ctl: float[], atl: float[], tsb: float[], dailyLoad: array<string,float>, modelStart: string|null}
     */
    public function series(Collection $activities, int $windowDays = 120): array
    {
        $dailyLoad = [];
        foreach ($activities as $a) {
            if ($a->date === null) {
                continue;
            }
            $dailyLoad[$a->date] = ($dailyLoad[$a->date] ?? 0.0) + (float) ($a->training_load ?? 0);
        }

        if ($dailyLoad === []) {
            return ['dates' => [], 'ctl' => [], 'atl' => [], 'tsb' => [], 'dailyLoad' => [], 'modelStart' => null];
        }

        $first = min(array_keys($dailyLoad));
        $start = now()->subDays($windowDays)->toDateString();
        $cursor = CarbonImmutable::parse(min($first, $start));
        $end = CarbonImmutable::now();

        $kCtl = 1 - exp(-1 / self::CTL_TC);
        $kAtl = 1 - exp(-1 / self::ATL_TC);

        $ctl = $atl = 0.0;
        $dates = $ctlSeries = $atlSeries = $tsbSeries = [];
        $prevCtl = $prevAtl = 0.0;

        while ($cursor->lte($end)) {
            $d = $cursor->toDateString();
            $load = $dailyLoad[$d] ?? 0.0;
            $prevCtl = $ctl;
            $prevAtl = $atl;
            $ctl += ($load - $ctl) * $kCtl;
            $atl += ($load - $atl) * $kAtl;

            if ($d >= $start) {
                $dates[] = $d;
                $ctlSeries[] = round($ctl, 1);
                $atlSeries[] = round($atl, 1);
                $tsbSeries[] = round($prevCtl - $prevAtl, 1);
            }
            $cursor = $cursor->addDay();
        }

        return [
            'dates' => $dates,
            'ctl' => $ctlSeries,
            'atl' => $atlSeries,
            'tsb' => $tsbSeries,
            'dailyLoad' => $dailyLoad,
            'modelStart' => $first,
        ];
    }

    /**
     * ACWR: prefer Garmin's own acute/chronic values, fall back to a
     * 7-day sum vs. 28-day weekly average from per-activity load.
     *
     * @return array{value: float|null, source: string, acute: float|null, chronic: float|null}
     */
    public function acwr(Collection $trainingStatus, array $dailyLoad): array
    {
        $latest = $trainingStatus->whereNotNull('acwr')->sortBy('date')->last();
        if ($latest && $latest->acwr) {
            return [
                'value' => round((float) $latest->acwr, 2),
                'source' => 'garmin',
                'acute' => $latest->acute_load !== null ? (float) $latest->acute_load : null,
                'chronic' => $latest->chronic_load !== null ? (float) $latest->chronic_load : null,
            ];
        }

        $acute = $chronic = 0.0;
        foreach ($dailyLoad as $date => $load) {
            $age = now()->startOfDay()->diffInDays(Carbon::parse($date)->startOfDay());
            if ($age < 7) {
                $acute += $load;
            }
            if ($age < 28) {
                $chronic += $load;
            }
        }
        $chronicWeekly = $chronic / 4.0;

        return [
            'value' => $chronicWeekly > 0 ? round($acute / $chronicWeekly, 2) : null,
            'source' => 'computed',
            'acute' => round($acute, 1),
            'chronic' => round($chronicWeekly, 1),
        ];
    }

    /** Above this the ratio counts as critical; named so alert texts can quote it. */
    public const ACWR_CRITICAL = 1.5;

    public static function acwrStatus(?float $acwr): string
    {
        return match (true) {
            $acwr === null => 'unknown',
            $acwr < 0.8 => 'detraining',
            $acwr <= 1.3 => 'good',
            $acwr <= self::ACWR_CRITICAL => 'warning',
            default => 'critical',
        };
    }

    /**
     * Ready words for each ratio status. They live next to the thresholds
     * they describe because every surface that prints the ratio (hero
     * tile, load gauge, deficit hints) must speak the same vocabulary,
     * and two places deriving it separately is how they drift apart.
     *
     * @return array<string, string>
     */
    public static function acwrWords(): array
    {
        return [
            'good' => __('In the corridor'), 'warning' => __('Upper edge'),
            'critical' => __('Too steep'), 'detraining' => __('Underloaded'),
            'unknown' => __('No value'),
        ];
    }
}
