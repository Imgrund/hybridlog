<?php

namespace App\View\Dashboard;

use App\Demo\DemoMode;
use App\Garmin\DataQuality;
use App\Garmin\FetchTrigger;
use App\Garmin\GarminData;
use App\Garmin\Insights;
use App\Garmin\MuscleFreshness;
use App\Garmin\NumberFormat;
use App\Garmin\TrainingLoad;
use App\Models\SymptomLog;
use App\Models\User;
use App\View\Heartbeat;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Assembles the complete view payload of the one dashboard page. The
 * controller stays thin; everything the page needs (verdict inputs,
 * areas and tabs, card details) is built here, on top of the chart
 * bundle the range switch also serves.
 */
class SurfacePage
{
    public function __construct(
        private GarminData $garmin,
        private TrainingLoad $trainingLoad,
        private MuscleFreshness $muscleFreshness,
        private Insights $insights,
        private ChartBundle $charts,
    ) {}

    public function render(): View
    {
        $data = $this->charts->cachedChartData(ChartBundle::DEFAULT_RANGE);
        $dayRows = $data['dayRows'];
        $sleep = $data['sleep'];
        $hrv = $data['hrv'];
        $pmc = $data['pmcFull'];
        $latestHrv = $data['latestHrv'];
        $sleepConsistency = $data['sleepConsistency'];

        $sets = $this->garmin->strengthSets(90);
        $trainingStatus = $this->garmin->trainingStatus(120);

        $acwr = $this->trainingLoad->acwr($trainingStatus, $pmc['dailyLoad']);
        $systems = $this->insights->systems(
            $dayRows, $sleep, $hrv, $latestHrv, $acwr, $sleepConsistency, $this->garmin->heartProfile()
        );
        // Early illness pattern: shown as a banner above the tabs and
        // hung into the body-map systems it is made of.
        $illness = $this->insights->illnessWarning($dayRows, $sleep, $latestHrv);
        $systems = $this->insights->applyIllnessWarning($systems, $illness);
        // Symptoms volunteered in the chat over the last 48 hours appear
        // ONLY as this context line on the banner, nowhere else.
        $illnessSymptomLine = null;
        if ($illness !== null) {
            $recent = SymptomLog::for(auth()->user())
                ->where('logged_at', '>=', now()->subHours(48))->orderByDesc('logged_at')->get();
            if ($recent->isNotEmpty()) {
                $dayWord = fn (string $d) => match ($d) {
                    now()->toDateString() => __('today'),
                    now()->subDay()->toDateString() => __('yesterday'),
                    default => __('the day before yesterday'),
                };
                $illnessSymptomLine = __('Also reported: :symptoms', [
                    'symptoms' => $recent->map(fn ($s) => $s->symptom.', '.$dayWord($s->date))->implode(' · '),
                ]);
            }
        }
        $freshness = $this->muscleFreshness->compute($sets, $data['activities']);

        $lastDay = $dayRows->sortBy('date')->last();

        // Second layer behind the cards that can honestly answer one more
        // question. A null entry means the mirror has nothing beyond what
        // the card already shows; that card then renders no expand
        // affordance at all.
        $details = [
            'strengthSessions' => $this->strengthSessionsDetail($data['activities']),
            'intensitySplit' => $this->intensitySplitDetail($dayRows),
        ];

        // Anchor of the metabolism system: the fitness-age gap.
        $fitnessAge = $this->garmin->fitnessAge();

        // Loaded once for the three readers below: the metabolism system,
        // the weight tile and the body-composition marker.
        $bodyComp = $this->garmin->bodyComp();

        // Fifth body-map system: metabolism / longevity, anchored on the
        // fitness-age gap the cohort block below also builds on.
        $systems['metabolism'] = $this->insights->metabolism(
            $fitnessAge, $bodyComp->last(), $lastDay, $dayRows
        );

        $rangeLimit = $this->charts->rangeLimit();

        $payload = [
            // The range switch fetches the same charts shape from
            // dashboard.charts; the initial page carries the default window.
            'range' => $this->charts->openingRange($rangeLimit),
            'rangeOptions' => ChartBundle::RANGES,
            'rangeLimit' => $rangeLimit,
            'chartsUrl' => route('dashboard.charts'),
            'charts' => $this->charts->surfaceCharts($data['charts']),
            'i18n' => $this->charts->i18n(),
            'bodymap' => [
                'freshness' => $freshness['zones'],
                'hasStrengthData' => $freshness['hasData'],
            ],
        ];

        $layout = $this->areaTabs();

        return view('dashboard', [
            'payload' => $payload,
            'kpi' => $data['kpi'],
            'meta' => $data['meta'],
            'details' => $details,
            'tabs' => $layout['tabs'],
            'rangeAware' => $layout['rangeAware'],
            // Sits under the sleep and training charts, never on a card of
            // its own: the heat is only worth reading beside the metric it
            // might explain.
            'weatherInsight' => $data['weatherInsight'],
            'systems' => $systems,
            'illness' => $illness,
            'illnessSymptomLine' => $illnessSymptomLine,
            'freshness' => $freshness['zones'],
            // What the volume lens paints its ramp against, and the
            // reported complaints the map overlays on the zones.
            'volumeCeiling' => $freshness['volumeCeiling'] ?? [],
            'symptomZones' => SymptomLog::byZone(auth()->user()),
            'acwr' => $acwr,
            // One vocabulary for the ratio, kept beside the thresholds it
            // describes (see TrainingLoad::acwrWords): the load gauge and
            // the morning briefing print the same words.
            'acwrWord' => TrainingLoad::acwrWords()[$acwrStatus = TrainingLoad::acwrStatus($acwr['value'])],
            'acwrStamp' => [
                'good' => 'good', 'warning' => 'warning', 'critical' => 'critical',
                'detraining' => 'neutral', 'unknown' => 'neutral',
            ][$acwrStatus],
            // The pulse the drawn heart beats to, resolved once here so
            // the organ in the body map beats to the same reading the
            // HRV card prints.
            'heartbeat' => Heartbeat::from(
                $latestHrv?->weekly_avg,
                $latestHrv?->baseline_balanced_low,
                $latestHrv?->baseline_balanced_upper,
                $lastDay->resting_hr ?? null,
            ),
            // Same pattern for the progression card: any set on record
            // keeps the card, an empty display window words itself.
            'strengthProgression' => $data['strengthProgression'],
            'strengthSetsTotal' => $data['strengthSetsTotal'],
            'isDemo' => $this->garmin->isDemo(),
            // Two neighbouring facts that are not the same one: isDemo asks
            // the mirror whether its newest rows were seeded, demoMode asks
            // the installation whether it is the public shop window. A
            // laptop seeded for a look around is the first without being
            // the second, and the header says different things about each.
            'demoMode' => DemoMode::enabled(),
            'lastFetch' => $this->garmin->latestFetch(),
            // Not from the flash of the request that started it: a fetch
            // started anywhere else, the scheduled one included, is one
            // this page has to report too.
            'fetchRunning' => ($fetchRun = app(FetchTrigger::class)->currentRun()) !== null,
            // Counted here as well as in the poll, so a page opened (or
            // reloaded) in the middle of a first fetch says "day 34 of
            // 90" from its first paint instead of ten polled seconds of
            // saying nothing.
            'fetchProgress' => $this->garmin->fetchProgress($fetchRun),
            'watchSyncedAt' => $watchSyncedAt = $this->garmin->watchLastSync(),
            'dataStatus' => $dataStatus = $this->garmin->dataStatus(),
            // Whether the numbers below are standing on complete data. A
            // strip rather than a card: it qualifies everything on the
            // page, so it belongs above all of it and below none of it.
            'dataQuality' => app(DataQuality::class)->evaluate(
                $dayRows, $data['activities'], $dataStatus, $watchSyncedAt
            ),
            'aiConnected' => self::aiConnected(auth()->user()),
        ]);
    }

    /**
     * The two fixed areas of the page. The stable ids remain the anchor
     * keys of old deep links (#koerperkarte, #belastung).
     *
     * @return array{tabs: array<int, array>, rangeAware: array<int, string>}
     */
    private function areaTabs(): array
    {
        $tabs = [];
        foreach ($this->areas() as $id => $area) {
            $tabs[] = [
                'id' => $id,
                'label' => $area['label'],
                'range' => $area['range'],
            ];
        }

        return [
            'tabs' => $tabs,
            'rangeAware' => array_values(array_column(array_filter($tabs, fn ($t) => $t['range']), 'id')),
        ];
    }

    /**
     * @return array<string, array{label: string, range: bool}>
     */
    private function areas(): array
    {
        return [
            'koerperkarte' => ['label' => __('Body map'), 'range' => false],
            'belastung' => ['label' => __('Load'), 'range' => true],
        ];
    }

    /**
     * Whether this user's connector can still act: an unrevoked,
     * unexpired access token, or an unexpired refresh token it could
     * trade in. Revoking both is exactly what "disconnect" does.
     * Refresh tokens carry no user_id of their own, so they are reached
     * through the access tokens they belong to.
     */
    public static function aiConnected(User $user): bool
    {
        $tokens = DB::table('oauth_access_tokens')->where('user_id', $user->id);

        return $tokens->clone()
            ->where('revoked', false)->where('expires_at', '>', now())->exists()
            || DB::table('oauth_refresh_tokens')
                ->whereIn('access_token_id', $tokens->clone()->select('id'))
                ->where('revoked', false)->where('expires_at', '>', now())->exists();
    }

    /**
     * The sessions behind the weekly strength-load bars, newest week
     * first: the card aggregates per week, so a bar's composition is
     * exactly what it cannot show. Fixed window of the current and the
     * three previous ISO weeks, independent of the chart range; only
     * load-bearing sessions appear, mirroring the chart's own filter.
     *
     * @return array<int, array{label: string, sum: string, sessions: array}>|null
     */
    private function strengthSessionsDetail(Collection $activities): ?array
    {
        $strengthTypes = ['hiit', 'strength_training', 'indoor_cardio', 'fitness_equipment'];
        $typeWords = [
            'hiit' => __('HIIT'),
            'strength_training' => __('Strength'),
            'indoor_cardio' => __('Cardio'),
            'fitness_equipment' => __('Machines'),
        ];
        $since = now()->startOfWeek()->subWeeks(3)->toDateString();
        $currentWeek = now()->format('o-\WW');

        $weeks = [];
        foreach ($activities as $a) {
            if (! $a->training_load || ! $a->date || $a->date < $since || ! in_array($a->type_key, $strengthTypes, true)) {
                continue;
            }
            // Round per session, then sum. Summing the raw floats and
            // rounding once at the end reads a week as 681 while the four
            // rows printed under it add up to 682, and this panel exists
            // precisely to show the sessions behind the number.
            $load = (int) round($a->training_load);
            $key = date('o-\WW', strtotime($a->date));
            $weeks[$key]['sum'] = ($weeks[$key]['sum'] ?? 0) + $load;
            $weeks[$key]['sessions'][] = [
                'date' => Carbon::parse($a->date)->isoFormat(__('dd DD/MM')),
                'type' => $typeWords[$a->type_key] ?? $a->type_key,
                'duration' => $a->duration_s ? round($a->duration_s / 60).' min' : '–',
                'load' => NumberFormat::format($load, 0),
                'hr' => $a->avg_hr !== null ? (string) $a->avg_hr : '–',
            ];
        }
        if ($weeks === []) {
            return null;
        }
        krsort($weeks);

        return collect($weeks)
            ->map(fn (array $week, string $key) => [
                'label' => __('Week :week', ['week' => (int) substr($key, 6)]).($key === $currentWeek ? ' '.__('(running)') : ''),
                'sum' => NumberFormat::format($week['sum'], 0),
                'sessions' => array_reverse($week['sessions']),
            ])
            ->values()
            ->all();
    }

    /**
     * Moderate versus vigorous minutes per week: the chart's WHO-weighted
     * sum (vigorous counts double) hides the composition, and for a
     * polarised plan the mix is the actionable part. Same fixed
     * four-ISO-week window as the sessions detail.
     *
     * @return array<int, array{label: string, moderate: int, vigorous: int, weighted: int}>|null
     */
    private function intensitySplitDetail(Collection $days): ?array
    {
        $since = now()->startOfWeek()->subWeeks(3)->toDateString();
        $currentWeek = now()->format('o-\WW');

        $weeks = [];
        foreach ($days as $d) {
            if ($d->date < $since) {
                continue;
            }
            $key = date('o-\WW', strtotime($d->date));
            $weeks[$key]['moderate'] = ($weeks[$key]['moderate'] ?? 0) + (int) ($d->intensity_moderate_min ?? 0);
            $weeks[$key]['vigorous'] = ($weeks[$key]['vigorous'] ?? 0) + (int) ($d->intensity_vigorous_min ?? 0);
        }
        if ($weeks === []) {
            return null;
        }
        krsort($weeks);

        return collect($weeks)
            ->map(fn (array $week, string $key) => [
                'label' => __('Week :week', ['week' => (int) substr($key, 6)]).($key === $currentWeek ? ' '.__('(running)') : ''),
                'moderate' => $week['moderate'],
                'vigorous' => $week['vigorous'],
                'weighted' => $week['moderate'] + 2 * $week['vigorous'],
            ])
            ->values()
            ->all();
    }
}
