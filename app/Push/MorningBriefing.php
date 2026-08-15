<?php

declare(strict_types=1);

namespace App\Push;

use App\Garmin\GarminData;
use App\Garmin\Insights;
use App\Garmin\MuscleFreshness;
use App\Garmin\NumberFormat;
use App\Garmin\TrainingLoad;
use Carbon\Carbon;

/**
 * The morning push in words: readiness, the verdict, and up to three
 * focus lines (open recovery time, a load ratio outside its corridor,
 * the most loaded muscle zone). Composed at the moment it
 * is needed, twice over:
 * app:morning-briefing asks whether there is anything to say before it
 * rings, and the push feed asks again when the service worker is about to
 * show it, so the numbers on the phone are the numbers of that moment.
 *
 * It reads the same sources the dashboard hero reads (intraday readiness
 * snapshot over the frozen morning value, the body-map core system, ACWR,
 * the illness cap), so briefing and hero can never disagree about the day.
 * The verdict word is derived here a second time rather than shared with
 * the hero, because the hero's derivation lives inside
 * dashboard.blade.php, and a view is nothing a command can read.
 */
class MorningBriefing
{
    /**
     * When open recovery time becomes the day's focus. A full night clears
     * about ten hours, so anything above this still reaches into tomorrow's
     * session; below it the timer is routine after any hard workout.
     */
    private const FOCUS_RECOVERY_HOURS = 18;

    /** Mirrors ChartBundle::ACTIVITY_HISTORY_DAYS so the ACWR fallback sees the same load history. */
    private const ACTIVITY_HISTORY_DAYS = 400;

    /** A zone fresher than this is routine load, not a focus line. */
    private const FOCUS_FRESHNESS_PCT = 70;

    public function __construct(
        private GarminData $garmin,
        private TrainingLoad $trainingLoad,
        private Insights $insights,
        private MuscleFreshness $muscleFreshness,
    ) {}

    /**
     * The briefing, or null while the mirror has nothing for today yet.
     * Null is the whole answer then: a briefing built on yesterday's
     * readiness would be a confident sentence about the wrong day.
     *
     * @return array{title: string, body: string, url: string}|null
     */
    public function compose(?Carbon $now = null): ?array
    {
        $now ??= Carbon::now();

        $readiness = $this->garmin->readiness(30)->sortBy('date')->last();

        if ($readiness === null || (string) $readiness->date !== $now->toDateString()) {
            return null;
        }

        // Same preference as the hero: the intraday snapshot wins over the
        // frozen wake-up value.
        $hasCurrent = ($readiness->current_score ?? null) !== null;
        $score = $hasCurrent ? (int) $readiness->current_score : ($readiness->score ?? null);

        if ($score === null) {
            return null;
        }

        $assessment = $this->assess((int) $score);

        $recoveryH = $hasCurrent ? $readiness->current_recovery_time_h : ($readiness->recovery_time_h ?? null);

        $sentences = array_values(array_filter([
            $this->recoveryFocus($recoveryH),
            $this->acwrFocus($assessment['acwr'], $assessment['acwrStatus']),
            $this->muscleFocus(),
        ]));

        return [
            'title' => __(':verdict, readiness :score', [
                'verdict' => $assessment['verdict'],
                'score' => (int) $score,
            ]),
            'body' => implode(' ', $sentences),
            'url' => route('dashboard'),
        ];
    }

    /**
     * The verdict word, derived exactly like the hero derives it: the
     * readiness band, the body-map core system (after the illness pattern
     * has been hung into it), the ACWR band, and the illness cap that
     * allows at most "Limited" while the pattern is active. The ACWR it
     * computes on the way rides along, so the focus line and the verdict
     * can never read two different ratios.
     *
     * @return array{verdict: string, acwr: array, acwrStatus: string}
     */
    private function assess(int $score): array
    {
        $days = $this->garmin->days(60);
        $sleep = $this->garmin->sleep(60);
        $hrv = $this->garmin->hrv(30);
        $latestHrv = $hrv->sortBy('date')->last();

        $acwr = $this->trainingLoad->acwr(
            $this->garmin->trainingStatus(120),
            $this->trainingLoad->series($this->garmin->activities(self::ACTIVITY_HISTORY_DAYS))['dailyLoad'],
        );
        $acwrStatus = TrainingLoad::acwrStatus($acwr['value']);

        $systems = $this->insights->systems(
            $days, $sleep, $hrv, $latestHrv, $acwr,
            $this->insights->sleepConsistency($sleep),
            $this->garmin->heartProfile(),
        );
        $illness = $this->insights->illnessWarning($days, $sleep, $latestHrv);
        $systems = $this->insights->applyIllnessWarning($systems, $illness);
        $coreStatus = $systems['core']['status'] ?? 'good';

        $readinessStatus = match (true) {
            $score >= 75 => 'good',
            $score >= 50 => 'warning',
            $score >= 25 => 'serious',
            default => 'critical',
        };

        $verdict = match (true) {
            $readinessStatus === 'critical' || $coreStatus === 'critical' || $acwrStatus === 'critical' => ['critical', __('Recovery')],
            $readinessStatus === 'serious' || $coreStatus === 'serious' => ['serious', __('Limited')],
            $readinessStatus === 'warning' || $coreStatus === 'warning' || $acwrStatus === 'warning' => ['warning', __('Moderate')],
            default => ['good', __('Ready')],
        };

        $word = $illness !== null && $verdict[0] !== 'critical' ? __('Limited') : $verdict[1];

        return ['verdict' => $word, 'acwr' => $acwr, 'acwrStatus' => $acwrStatus];
    }

    /**
     * Each focus line speaks only when its model has something off the
     * routine: open recovery past the threshold, a ratio outside its
     * corridor, a zone still carrying real load. A quiet morning sends
     * the verdict alone; the full report lives in the chat.
     */
    private function recoveryFocus(mixed $recoveryH): ?string
    {
        if ($recoveryH !== null && (float) $recoveryH > self::FOCUS_RECOVERY_HOURS) {
            return __(':hours h recovery time still open.', ['hours' => (int) round((float) $recoveryH)]);
        }

        return null;
    }

    /** The ratio line, only outside the corridor; inside it is routine. */
    private function acwrFocus(array $acwr, string $acwrStatus): ?string
    {
        if ($acwr['value'] === null || ! in_array($acwrStatus, ['warning', 'critical', 'detraining'], true)) {
            return null;
        }

        return __('Load ratio :value: :word.', [
            'value' => NumberFormat::format($acwr['value'], 1),
            'word' => TrainingLoad::acwrWords()[$acwrStatus],
        ]);
    }

    /**
     * The most loaded muscle zone, worded like the body map words it,
     * and only while a zone is markedly below fresh.
     */
    private function muscleFocus(): ?string
    {
        $freshness = $this->muscleFreshness->compute(
            $this->garmin->strengthSets(90),
            $this->garmin->activities(self::ACTIVITY_HISTORY_DAYS),
        );

        $heaviest = collect($freshness['zones'] ?? [])
            ->filter(fn (array $zone) => ($zone['hasData'] ?? false)
                && ($zone['freshness'] ?? 100) < self::FOCUS_FRESHNESS_PCT)
            ->sortBy('freshness');
        if ($heaviest->isEmpty()) {
            return null;
        }

        return __('Most loaded: :zone, :percent % fresh.', [
            'zone' => MuscleFreshness::zoneLabels()[$heaviest->keys()->first()] ?? $heaviest->keys()->first(),
            'percent' => $heaviest->first()['freshness'],
        ]);
    }
}
