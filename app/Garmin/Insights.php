<?php

namespace App\Garmin;

use Illuminate\Support\Collection;

/**
 * Rule-based interpretation layer: turns raw metrics into the four
 * body-system markers (heart, head/sleep, lungs, recovery core) with a
 * status and a concrete recommendation. Statuses use the reserved
 * palette roles: good / warning / serious / critical.
 */
class Insights
{
    /**
     * Bedtime/wake regularity over the last 14 nights.
     *
     * The median bedtime rides along as a clock string because it is the
     * one number a "keep your window" advice can name: the spread says the
     * window is drifting, the median says where it usually sits.
     *
     * @return array{bedtimeSdMin: int|null, wakeSdMin: int|null, bedtimeMedian: string|null, avgDurationH: float|null, avgScore: int|null}
     */
    public function sleepConsistency(Collection $sleep): array
    {
        $recent = $sleep->filter(fn ($s) => $s->start_local && $s->end_local)
            ->sortBy('date')->values()->slice(-14);

        $bedMinutes = [];
        $wakeMinutes = [];
        foreach ($recent as $s) {
            // Minutes since 18:00 avoids the midnight wrap for bedtimes.
            $bed = strtotime($s->start_local);
            $wake = strtotime($s->end_local);
            if (! $bed || ! $wake) {
                continue;
            }
            $bedM = ((int) date('H', $bed)) * 60 + (int) date('i', $bed);
            $bedMinutes[] = ($bedM + 24 * 60 - 18 * 60) % (24 * 60);
            $wakeMinutes[] = ((int) date('H', $wake)) * 60 + (int) date('i', $wake);
        }

        $last7 = $sleep->sortBy('date')->values()->slice(-7);

        return [
            'bedtimeSdMin' => $bedMinutes ? (int) round($this->stdDev($bedMinutes)) : null,
            'wakeSdMin' => $wakeMinutes ? (int) round($this->stdDev($wakeMinutes)) : null,
            'bedtimeMedian' => $bedMinutes ? $this->bedtimeClock((int) round(collect($bedMinutes)->median())) : null,
            'avgDurationH' => $last7->avg('duration_s') ? round($last7->avg('duration_s') / 3600, 1) : null,
            'avgScore' => $last7->avg('score') ? (int) round($last7->avg('score')) : null,
        ];
    }

    /** Minutes since 18:00 back into the clock time they encode. */
    private function bedtimeClock(int $minutesSince18): string
    {
        $minutes = ($minutesSince18 + 18 * 60) % (24 * 60);

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * The four body-map system markers. Each carries, next to status and
     * recommendation, a 30-day spark series and a facts list for the
     * organ-focused detail panel.
     */
    public function systems(
        Collection $days,
        Collection $sleep,
        Collection $hrv,
        ?object $latestHrv,
        array $acwr,
        array $sleepConsistency,
        ?object $heartProfile = null,
    ): array {
        $systems = [];
        $lastDay = $days->sortBy('date')->last();
        $lastSleep = $sleep->sortBy('date')->last();
        $fmt = fn (?float $v, int $dec = 0) => $v === null ? '–' : NumberFormat::format($v, $dec);

        // ---- Heart: RHR vs. its own 28d baseline, VO2max 28d trend
        // Without today's provisional reading, where one is recognisable:
        // a single revised-away spike shifts the 7-day mean by up to
        // 2 bpm, most of the way to the warning threshold below.
        $rhrDays = $days->reject(fn ($r) => $this->provisionalRestingHr($r));
        $rhr7 = $this->windowAvg($rhrDays, 'resting_hr', 7);
        $rhr28 = $this->windowAvg($rhrDays, 'resting_hr', 28);
        $vo2Now = $this->lastValue($days, 'vo2max_running');
        $vo2Prev = $this->valueDaysAgo($days, 'vo2max_running', 28);
        $vo2Delta = ($vo2Now !== null && $vo2Prev !== null) ? round($vo2Now - $vo2Prev, 1) : null;
        $rhrDelta = ($rhr7 !== null && $rhr28 !== null) ? round($rhr7 - $rhr28, 1) : null;

        $status = 'good';
        $recommendation = __('Cardiovascular side is in the green. Keep the zone 2 volume, one threshold session a week is enough.');
        if ($rhrDelta !== null && $rhrDelta >= 5) {
            $status = 'critical';
            $recommendation = __('Resting heart rate clearly above your baseline: no hard training today, watch for signs of infection or overreaching.');
        } elseif (($rhrDelta !== null && $rhrDelta >= 2.5) || ($vo2Delta !== null && $vo2Delta <= -1.0)) {
            $status = 'warning';
            $recommendation = $vo2Delta !== null && $vo2Delta <= -1.0
                ? __('VO2max trend is falling: plan more low-intensity running volume and run the hard sessions with a chest strap.')
                : __('Resting heart rate slightly raised: keep the intensity moderate today and prioritise sleep.');
        }
        // Threshold pace from Garmin's m/s value, formatted mm:ss per km.
        $lthrPace = null;
        if (($heartProfile->lthr_speed_ms ?? null) > 0) {
            $secPerKm = (int) round(1000 / $heartProfile->lthr_speed_ms);
            $lthrPace = intdiv($secPerKm, 60).':'.str_pad((string) ($secPerKm % 60), 2, '0', STR_PAD_LEFT);
        }

        $systems['heart'] = [
            'label' => __('Cardiovascular'),
            'status' => $status,
            'value' => $vo2Now !== null ? NumberFormat::format($vo2Now, 1).' VO2max' : '–',
            'recommendation' => $recommendation,
            'spark' => $this->series($days, 'resting_hr', 30),
            'sparkLabel' => __('Resting heart rate, 30 days'),
            'facts' => array_values(array_filter([
                ($lastDay->resting_hr ?? null) !== null
                    ? ['label' => __('Resting heart rate today'), 'value' => $lastDay->resting_hr.' bpm'] : null,
                ($lastDay->min_hr ?? null) !== null && ($lastDay->max_hr ?? null) !== null
                    ? ['label' => __('Range today'), 'value' => $lastDay->min_hr.'–'.$lastDay->max_hr.' bpm'] : null,
                $rhr7 !== null
                    ? ['label' => __('Ø 7 days / baseline 28'), 'value' => round($rhr7).' / '.($rhr28 !== null ? round($rhr28) : '–').' bpm'] : null,
                ($heartProfile->lthr_bpm ?? null) !== null
                    ? ['label' => __('Lactate threshold'), 'value' => $heartProfile->lthr_bpm.' bpm'.($lthrPace ? ' · '.$lthrPace.' /km' : '')] : null,
                $vo2Delta !== null
                    ? ['label' => __('VO2max trend, 28 days'), 'value' => ($vo2Delta >= 0 ? '+' : '').$fmt($vo2Delta, 1)] : null,
            ])),
            'zones' => ($heartProfile->zone1_floor ?? null) !== null ? [
                'floors' => [
                    (int) $heartProfile->zone1_floor,
                    (int) $heartProfile->zone2_floor,
                    (int) $heartProfile->zone3_floor,
                    (int) $heartProfile->zone4_floor,
                    (int) $heartProfile->zone5_floor,
                ],
                'max' => (int) $heartProfile->max_hr,
                'lthr' => $heartProfile->lthr_bpm !== null ? (int) $heartProfile->lthr_bpm : null,
            ] : null,
        ];

        // ---- Head: sleep regularity beats duration (SRI evidence)
        $sd = $sleepConsistency['bedtimeSdMin'];
        $score7 = $sleepConsistency['avgScore'];
        $status = 'good';
        $recommendation = __('Sleep window is stable. Keep it exactly like this, regularity is your strongest longevity lever in sleep.');
        if ($sd !== null && $sd > 60) {
            $status = 'serious';
            $recommendation = __('Your bedtime varies by ±:minutes minutes. A fixed window (say 22:30 to 23:00) buys you more than any sleep-score tuning.', ['minutes' => $sd]);
        } elseif (($sd !== null && $sd > 35) || ($score7 !== null && $score7 < 65)) {
            $status = 'warning';
            $recommendation = $sd !== null && $sd > 35
                ? __('Bedtime varies by ±:minutes minutes: try a constant window for the next 7 nights.', ['minutes' => $sd])
                : __('This week\'s sleep score is weak: look at the evening routine (late training, alcohol, screen time).');
        }
        $phases = null;
        if (($lastSleep->deep_s ?? null) !== null || ($lastSleep->rem_s ?? null) !== null) {
            $h = fn (?int $s) => $s !== null ? NumberFormat::format($s / 3600, 1) : '–';
            $phases = __('Deep').' '.$h($lastSleep->deep_s).' · REM '.$h($lastSleep->rem_s).' · '.__('Light').' '.$h($lastSleep->light_s).' h';
        }

        $systems['head'] = [
            'label' => __('Sleep and recovery'),
            'status' => $status,
            'value' => $score7 !== null ? __(':score score (7 d)', ['score' => $score7]) : '–',
            'recommendation' => $recommendation,
            'spark' => $this->series($sleep, 'score', 30),
            'sparkLabel' => __('Sleep score, 30 nights'),
            'facts' => array_values(array_filter([
                ($lastSleep->score ?? null) !== null
                    ? ['label' => __('Last night'), 'value' => __(':score score', ['score' => $lastSleep->score]).' · '.$fmt(($lastSleep->duration_s ?? 0) / 3600, 1).' h'] : null,
                $phases !== null ? ['label' => __('Phases'), 'value' => $phases] : null,
                $sd !== null ? ['label' => __('Bedtime spread'), 'value' => '±'.$sd.' min'] : null,
                $sleepConsistency['wakeSdMin'] !== null
                    ? ['label' => __('Wake-time spread'), 'value' => '±'.$sleepConsistency['wakeSdMin'].' min'] : null,
            ])),
        ];

        // ---- Lungs: nightly respiration vs. 28d baseline (early illness flag)
        $resp7 = $this->windowAvg($sleep, 'respiration_avg', 7);
        $resp28 = $this->windowAvg($sleep, 'respiration_avg', 28);
        $respDelta = ($resp7 !== null && $resp28 !== null) ? round($resp7 - $resp28, 1) : null;
        $status = 'good';
        $recommendation = __('Nightly breathing rate is unremarkable.');
        if ($respDelta !== null && $respDelta >= 2.5) {
            $status = 'serious';
            $recommendation = __('Breathing rate clearly above baseline, an early marker of infection: back off the load and keep watching.');
        } elseif ($respDelta !== null && $respDelta >= 1.5) {
            $status = 'warning';
            $recommendation = __('Breathing rate slightly raised: listen closely today to how the training feels.');
        }
        // Nightly pulse ox rides on the same panel. The columns stay null
        // (or absent on an unmigrated mirror) while the watch sensor is
        // off; the panel then explains how to enable it instead of
        // pretending values.
        $lastSpo2 = $days
            ->filter(fn ($d) => ($d->spo2_avg ?? null) !== null && $d->date >= now()->subDays(30)->toDateString())
            ->sortBy('date')->last();
        $spo2Fact = $lastSpo2 !== null
            ? ['label' => __('SpO2 last night'), 'value' => 'Ø '.$fmt((float) $lastSpo2->spo2_avg, 0).' %'
                .(($lastSpo2->spo2_lowest ?? null) !== null ? ' · '.__('low').' '.$fmt((float) $lastSpo2->spo2_lowest, 0).' %' : '')]
            : null;

        $systems['lungs'] = [
            'label' => __('Breathing'),
            'status' => $status,
            'value' => $resp7 !== null ? NumberFormat::format($resp7, 1).' brpm' : '–',
            'recommendation' => $recommendation,
            'spark' => $this->series($sleep, 'respiration_avg', 30),
            'sparkLabel' => __('Nightly breathing rate, 30 nights'),
            'facts' => array_values(array_filter([
                ($lastSleep->respiration_avg ?? null) !== null
                    ? ['label' => __('Last night'), 'value' => $fmt((float) $lastSleep->respiration_avg, 1).' brpm'
                        .(($lastSleep->respiration_lowest ?? null) !== null ? ' ('.$fmt((float) $lastSleep->respiration_lowest, 0).'–'.$fmt((float) $lastSleep->respiration_highest, 0).')' : '')] : null,
                $resp28 !== null
                    ? ['label' => __('Baseline, 28 nights'), 'value' => $fmt($resp28, 1).' brpm'] : null,
                $respDelta !== null
                    ? ['label' => __('Deviation'), 'value' => ($respDelta >= 0 ? '+' : '').$fmt($respDelta, 1).' brpm'] : null,
                $spo2Fact,
            ])),
            'help' => $lastSpo2 === null
                ? __('SpO2 is missing: the pulse oximeter is switched off on the watch. Turn it on under Settings → Health & Wellness → Pulse Ox → During Sleep (the path varies slightly by model).')
                : null,
        ];

        // ---- Recovery core: Garmin HRV status + weekly avg vs. baseline band + ACWR
        $hrvStatus = $latestHrv->status ?? null;
        $weekly = $latestHrv->weekly_avg ?? null;
        $bandLow = $latestHrv->baseline_balanced_low ?? null;
        $bandUp = $latestHrv->baseline_balanced_upper ?? null;
        $acwrVal = $acwr['value'];
        $acwrState = TrainingLoad::acwrStatus($acwrVal);

        $status = 'good';
        $recommendation = __('HRV inside your own normal band and load in the green corridor: ready for hard sessions.');
        if ($hrvStatus === 'LOW' || $hrvStatus === 'POOR') {
            $status = 'critical';
            $recommendation = __('HRV clearly below your normal band: 2 to 3 days of deload (zone 1/2, sleep, no metcon), then look again.');
        } elseif ($hrvStatus === 'UNBALANCED' || $acwrState === 'critical') {
            $status = $acwrState === 'critical' ? 'serious' : 'warning';
            $recommendation = $acwrState === 'critical'
                ? __('Acute load :ratio times your chronic load: injury-risk territory, take volume out this week.', ['ratio' => NumberFormat::format((float) $acwrVal, 2)])
                : __('HRV is off your baseline (overshooting counts too): cap the intensity for a day.');
        } elseif ($acwrState === 'warning') {
            $status = 'warning';
            $recommendation = __('Load is rising along the upper edge (ACWR above 1.3): plan the next hard session deliberately.');
        } elseif ($acwrState === 'detraining') {
            $recommendation = __('Load is below your usual level: a good moment for a progressive block.');
        }
        $systems['core'] = [
            'label' => __('Autonomic recovery (HRV)'),
            'status' => $status,
            'value' => $weekly !== null ? NumberFormat::format((float) $weekly, 0).' '.__('ms (7 d)') : '–',
            'recommendation' => $recommendation,
            'spark' => $this->series($hrv, 'last_night_avg', 30),
            'sparkLabel' => __('HRV last night, 30 nights'),
            'facts' => array_values(array_filter([
                ($latestHrv->last_night_avg ?? null) !== null
                    ? ['label' => __('Last night'), 'value' => round((float) $latestHrv->last_night_avg).' ms'] : null,
                $bandLow !== null && $bandUp !== null
                    ? ['label' => __('Normal band'), 'value' => round($bandLow).'–'.round($bandUp).' ms · '.($hrvStatus ?? '–')] : null,
                ($lastDay->bb_low ?? null) !== null && ($lastDay->bb_high ?? null) !== null
                    ? ['label' => __('Body Battery today'), 'value' => $lastDay->bb_low.'–'.$lastDay->bb_high] : null,
                ($lastDay->stress_avg ?? null) !== null
                    ? ['label' => __('Stress today'), 'value' => 'Ø '.$lastDay->stress_avg.($lastDay->stress_max !== null ? ' · max '.$lastDay->stress_max : '')] : null,
                $acwrVal !== null
                    ? ['label' => 'ACWR', 'value' => NumberFormat::format($acwrVal, 2)] : null,
            ])),
        ];

        return $systems;
    }

    /**
     * Fifth body system: metabolism / body composition, the longevity view.
     * Status compares fitness age against chronological age (Oura frames
     * its Cardiovascular Age the same way).
     */
    public function metabolism(?object $fitnessAge, ?object $lastWeight, ?object $lastDay, Collection $days): array
    {
        $chrono = $fitnessAge->chronological_age ?? null;
        $fit = $fitnessAge->fitness_age ?? null;
        $gap = ($chrono !== null && $fit !== null) ? round($fit - $chrono, 1) : null;

        $status = 'good';
        $recommendation = __('Fitness age below your chronological age: the single strongest longevity marker is in good shape.');
        if ($gap !== null && $gap > 4) {
            $status = 'serious';
            $recommendation = __('Fitness age clearly above your chronological age: VO2max work (zone 2 plus one interval session) is the most effective lever.');
        } elseif ($gap !== null && $gap > 0) {
            $status = 'warning';
            $recommendation = __('Fitness age slightly above your chronological age: raise the endurance volume and keep your weight steady.');
        }

        $weightKg = ($lastWeight->weight_g ?? null) !== null ? $lastWeight->weight_g / 1000 : null;
        $sweatL = ($lastDay->sweat_loss_ml ?? null) !== null ? $lastDay->sweat_loss_ml / 1000 : null;

        return [
            'label' => __('Metabolism and body'),
            'status' => $status,
            'value' => $fit !== null ? __(':years fitness age', ['years' => NumberFormat::format($fit, 1)]) : '–',
            'recommendation' => $recommendation,
            'spark' => $this->series($days, 'calories_active', 30),
            'sparkLabel' => __('Active calories, 30 days'),
            'facts' => array_values(array_filter([
                $gap !== null
                    ? ['label' => __('Vs. chronological age'), 'value' => ($gap > 0 ? '+' : '').NumberFormat::format($gap, 1).' '.__('years')] : null,
                $weightKg !== null
                    ? ['label' => __('Weight'), 'value' => NumberFormat::format($weightKg, 1).' kg'] : null,
                ($lastDay->calories_active ?? null) !== null
                    ? ['label' => __('Active calories today'), 'value' => NumberFormat::format($lastDay->calories_active, 0).' kcal'] : null,
                $sweatL !== null && $sweatL > 0
                    ? ['label' => __('Sweat loss today'), 'value' => NumberFormat::format($sweatL, 1).' l'] : null,
            ])),
        ];
    }

    /**
     * Today's resting HR is provisional this far above the day's own HR
     * floor. Garmin revises the value over the day, and the early reading
     * can sit far above where it settles; on 60 finished days the gap to
     * min_hr never exceeded 7 bpm, so 10 keeps a margin while still
     * catching the double-digit provisional spikes that would read as
     * illness.
     */
    private const PROVISIONAL_RHR_GAP = 10;

    /**
     * Early illness pattern across three markers. Baseline is the median
     * over 30 days excluding the last two, so the potential onset days
     * cannot drag the baseline toward the deviation. Fires when at least
     * two of three criteria hold, resting heart rate mandatory among
     * them: RHR >= baseline + 5 bpm, nightly respiration >= baseline + 2,
     * HRV last night under the balanced band or 10 %+ under the weekly
     * average. Two criteria: warning, all three: serious. A pattern hint,
     * never a diagnosis, and the wording on every surface says so.
     *
     * @return array{status: string, message: string, criteria: array{rhr: bool, resp: bool, hrv: bool}, rhrDelta: float, respDelta: float|null, hrvNote: string|null}|null
     */
    public function illnessWarning(Collection $days, Collection $sleep, ?object $latestHrv): ?array
    {
        // Current values must come from the excluded window (today or
        // yesterday): an older reading would compare the baseline era
        // against itself. Today's value is only trusted when it sits near
        // the day's own HR floor; a provisional reading is rejected here
        // so yesterday's final one takes over rather than faking an onset.
        $currentSince = now()->subDays(1)->toDateString();
        $rhrRow = $days
            ->filter(fn ($r) => $r->resting_hr !== null && $r->date >= $currentSince)
            ->reject(fn ($r) => $this->provisionalRestingHr($r))
            ->sortBy('date')->last();
        $respRow = $sleep->filter(fn ($r) => $r->respiration_avg !== null && $r->date >= $currentSince)->sortBy('date')->last();

        $rhrBase = $this->baselineMedian($days, 'resting_hr');
        if ($rhrRow === null || $rhrBase === null) {
            return null;
        }

        $rhrDelta = round((float) $rhrRow->resting_hr - $rhrBase, 1);
        if ($rhrDelta < 5) {
            return null;
        }

        $respBase = $this->baselineMedian($sleep, 'respiration_avg');
        $respDelta = ($respRow !== null && $respBase !== null)
            ? round((float) $respRow->respiration_avg - $respBase, 1)
            : null;
        $respHit = $respDelta !== null && $respDelta >= 2;

        $hrvNight = $latestHrv->last_night_avg ?? null;
        $bandLow = $latestHrv->baseline_balanced_low ?? null;
        $weekly = $latestHrv->weekly_avg ?? null;
        $underBand = $hrvNight !== null && $bandLow !== null && (float) $hrvNight < (float) $bandLow;
        $underWeekly = $hrvNight !== null && $weekly !== null && (float) $weekly > 0
            && (float) $hrvNight <= (float) $weekly * 0.9;
        $hrvHit = $underBand || $underWeekly;
        $hrvNote = match (true) {
            $underBand => __('HRV last night below the normal band'),
            $underWeekly => __('HRV last night :percent % below the weekly average', ['percent' => (int) round((1 - (float) $hrvNight / (float) $weekly) * 100)]),
            default => null,
        };

        $hits = 1 + (int) $respHit + (int) $hrvHit;
        if ($hits < 2) {
            return null;
        }

        // The resting-heart-rate part always leads the sentence, the other
        // two never do, which is why only this one is capitalised.
        $parts = [__('Resting heart rate +:bpm bpm above your baseline', ['bpm' => NumberFormat::format($rhrDelta, 0)])];
        if ($respHit) {
            $parts[] = __('breathing rate +:breaths breaths', ['breaths' => NumberFormat::format($respDelta, 1)]);
        }
        if ($hrvHit) {
            $parts[] = $hrvNote;
        }

        return [
            'status' => $hits === 3 ? 'serious' : 'warning',
            'message' => implode(', ', $parts).'.',
            'criteria' => ['rhr' => true, 'resp' => $respHit, 'hrv' => $hrvHit],
            'rhrDelta' => $rhrDelta,
            'respDelta' => $respDelta,
            'hrvNote' => $hrvNote,
        ];
    }

    /**
     * Whether a day's resting HR is an on-device provisional reading.
     *
     * Only today can be provisional: a finished day's value is what
     * Garmin settled on, and there a large gap to the floor would itself
     * be information. The floor is the day's min_hr, which by mid-morning
     * is the night's low; without it there is nothing to check against
     * and the value is taken at its word.
     */
    private function provisionalRestingHr(object $day): bool
    {
        if ($day->date !== now()->toDateString() || ($day->min_hr ?? null) === null) {
            return false;
        }

        return (float) $day->resting_hr - (float) $day->min_hr >= self::PROVISIONAL_RHR_GAP;
    }

    /**
     * Hang the illness pattern into the body-map systems it is made of:
     * each met criterion raises its system to at least the pattern's
     * status, adds a fact line and, where the pattern outranks the
     * system's own finding, replaces the recommendation.
     */
    public function applyIllnessWarning(array $systems, ?array $illness): array
    {
        if ($illness === null) {
            return $systems;
        }

        $rank = ['good' => 0, 'warning' => 1, 'serious' => 2, 'critical' => 3];
        $map = ['rhr' => 'heart', 'resp' => 'lungs', 'hrv' => 'core'];
        $facts = [
            'heart' => ['label' => __('Illness pattern'), 'value' => __('+:bpm bpm above baseline', ['bpm' => NumberFormat::format($illness['rhrDelta'], 0)])],
            'lungs' => ['label' => __('Illness pattern'), 'value' => $illness['respDelta'] !== null ? __('+:brpm brpm above baseline', ['brpm' => NumberFormat::format($illness['respDelta'], 1)]) : null],
            'core' => ['label' => __('Illness pattern'), 'value' => $illness['hrvNote']],
        ];

        foreach ($map as $criterion => $key) {
            if (! $illness['criteria'][$criterion] || ! isset($systems[$key])) {
                continue;
            }
            if ($rank[$illness['status']] > $rank[$systems[$key]['status']]) {
                $systems[$key]['status'] = $illness['status'];
                $systems[$key]['recommendation'] = __('Part of a conspicuous pattern across resting heart rate, breathing and HRV: take load out and keep watching. A pattern hint, not a diagnosis.');
            }
            if ($facts[$key]['value'] !== null) {
                $systems[$key]['facts'][] = $facts[$key];
            }
        }

        return $systems;
    }

    // ------------------------------------------------------------ helpers

    /**
     * Median of a column over the 30 days before the last two; null when
     * fewer than 14 values exist, because a median over a handful of
     * days is noise wearing a baseline's clothes.
     */
    private function baselineMedian(Collection $rows, string $column): ?float
    {
        $from = now()->subDays(31)->toDateString();
        $until = now()->subDays(2)->toDateString();
        $values = $rows
            ->filter(fn ($r) => $r->date >= $from && $r->date <= $until && $r->{$column} !== null)
            ->pluck($column)
            ->map(fn ($v) => (float) $v)
            ->sort()
            ->values();

        $n = $values->count();
        if ($n < 14) {
            return null;
        }

        return $n % 2 === 1
            ? $values[intdiv($n, 2)]
            : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
    }

    /** Last N non-null values of a dated series, oldest first. */
    private function series(Collection $rows, string $column, int $n): array
    {
        return $rows->sortBy('date')
            ->pluck($column)
            ->filter(fn ($v) => $v !== null)
            ->slice(-$n)
            ->map(fn ($v) => (float) $v)
            ->values()
            ->all();
    }

    private function windowAvg(Collection $rows, string $column, int $days): ?float
    {
        $cutoff = now()->subDays($days)->toDateString();
        $values = $rows->filter(fn ($r) => $r->date >= $cutoff && $r->{$column} !== null)
            ->pluck($column);

        return $values->isEmpty() ? null : (float) $values->avg();
    }

    private function lastValue(Collection $rows, string $column): ?float
    {
        $row = $rows->filter(fn ($r) => $r->{$column} !== null)->sortBy('date')->last();

        return $row ? (float) $row->{$column} : null;
    }

    private function valueDaysAgo(Collection $rows, string $column, int $days): ?float
    {
        $cutoff = now()->subDays($days)->toDateString();
        $row = $rows->filter(fn ($r) => $r->date <= $cutoff && $r->{$column} !== null)
            ->sortBy('date')->last();

        return $row ? (float) $row->{$column} : null;
    }

    private function stdDev(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $mean = array_sum($values) / $n;
        $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / ($n - 1);

        return sqrt($variance);
    }
}
