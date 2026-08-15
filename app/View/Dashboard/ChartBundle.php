<?php

namespace App\View\Dashboard;

use App\Garmin\GarminData;
use App\Garmin\Insights;
use App\Garmin\NumberFormat;
use App\Garmin\Stimulus;
use App\Garmin\StrengthProgression;
use App\Garmin\TrainingLoad;
use App\Garmin\Weather;
use Carbon\Carbon;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Cache;

/**
 * The windowed chart payload, KPI tiles and window descriptors of the
 * one dashboard page; the range switch swaps the same payload shape.
 */
class ChartBundle
{
    /** Selectable display windows in days; requests are validated against this hard allowlist. */
    public const RANGES = [7, 30, 90, 365];

    public const DEFAULT_RANGE = 90;

    /** Longest display window; chart collections always load this much history. */
    private const MAX_RANGE_DAYS = 365;

    /**
     * History fed into the cumulative models (PMC EWMA, muscle freshness).
     * Wider than any display window, so switching the range can never
     * change what the models see.
     */
    private const ACTIVITY_HISTORY_DAYS = 400;

    /** Weekly-bucketed cards floor here: below ~8 bars a trend cannot be read. */
    private const MIN_WEEKS = 8;

    /**
     * Named categories on the progression card; everything smaller folds
     * into Other. Three, because the stack has exactly three steps of
     * the series hue before Other's grey, and a fourth category would
     * either invent a colour or steal the comparison one.
     */
    private const PROGRESS_TOP_CATEGORIES = 3;

    /**
     * The chart bundle only translates its own fixed vocabulary. Shipping
     * the complete application catalogue with every dashboard response
     * added roughly 40 kB gzip before a single chart could render.
     */
    private const CHART_I18N_KEYS = [
        '7-day mean', ':count sessions, last on :date', ':series: no data in the selected window.',
        ':series: only one reading in this window, no trend yet.', 'ATL', 'Aerobic effect',
        'Anaerobic effect', 'Fatigue (ATL)', 'Fitness (CTL)', 'Form (TSB)', 'Intensity minutes',
        'Load', 'Minutes', 'Moderate', 'Night value', 'No data in the selected window.',
        'Normal band', 'Only one reading in this window, no trend yet.', 'Resting heart rate',
        'Resting heart rate, bpm', 'Strength load', 'This chart could not be loaded.',
        'Vigorous, counted double', 'WHO corridor', 'aerobic', 'anaerobic', 'still running',
    ];

    public function __construct(
        private GarminData $garmin,
        private TrainingLoad $trainingLoad,
        private Insights $insights,
    ) {}

    /**
     * Series names, axis titles and tooltip wording live in the chart
     * layer, which has no __(). It looks strings up by their English
     * source text, so shipping the locale's whole JSON map is both the
     * complete answer and no answer at all under the source language,
     * where the file does not exist.
     *
     * @return array<string, string>
     */
    public function i18n(): array
    {
        return array_intersect_key(
            app('translator')->getLoader()->load(app()->getLocale(), '*', '*'),
            array_flip(self::CHART_I18N_KEYS),
        );
    }

    /**
     * The longest window the range switch will offer, in days.
     *
     * A stage asks for a fixed number of days and the mirror either reaches
     * back that far or it does not. Four months of history has no year to
     * draw, and a 365 that quietly renders 125 days is worse than one that
     * says it cannot: the short line reads as a broken chart rather than as
     * a young log. Stages above this limit are rendered disabled, never
     * removed, because the shortfall is history and history accumulates. The
     * stage returns on its own, with nobody editing a list.
     *
     * The one exception is a mirror too young for even the shortest stage,
     * where gating would disable the whole control. A dead switch is worse
     * than a short chart, and a dashboard with barely a week in it says so
     * everywhere else already, so nothing is gated until the first stage is
     * within reach.
     */
    public function rangeLimit(): int
    {
        $span = $this->garmin->mirrorSpanDays();

        return $span >= min(self::RANGES) ? $span : self::MAX_RANGE_DAYS;
    }

    /**
     * The window the dashboard opens on: the usual default while the mirror
     * can draw it, otherwise the longest stage it can. Opening on a stage
     * that is itself disabled would leave the group reporting a selection
     * nobody can move off, and the first chart empty for no stated reason.
     */
    public function openingRange(int $limit): int
    {
        if ($limit >= self::DEFAULT_RANGE) {
            return self::DEFAULT_RANGE;
        }

        // Non-empty by construction: rangeLimit() only gates once the
        // shortest stage fits.
        return max(array_filter(self::RANGES, fn (int $r): bool => $r <= $limit));
    }

    public function chartResponse(Request $request): JsonResponse
    {
        // Hard allowlist: the request value itself never reaches a query or
        // any window math, only the matched constant does.
        $days = $request->integer('days');
        abort_unless(in_array($days, self::RANGES, true), 422);

        $data = $this->cachedChartData($days);

        $kpi = $this->kpiHtml($data['kpi']);
        // The progression rows ride the same [data-kpi] outerHTML swap as
        // the KPI tiles: they are a window reading (bests, stagnation), so
        // a range switch has to move them exactly like the tiles of the
        // neighbouring cards.
        $kpi['strengthProgress'] = view('partials.strength-progress', [
            'progress' => $data['strengthProgression'],
        ])->render();

        return response()->json([
            'range' => $days,
            'charts' => $this->surfaceCharts($data['charts']),
            'kpi' => $kpi,
            'meta' => $data['meta'],
        ]);
    }

    /**
     * Page and range endpoint read the same immutable Garmin mirror
     * snapshot. Cache the expensive 365/400-day model once per fetch rather
     * than rebuilding the chart set on every navigation. The fetch stamp is
     * part of the key, so newly mirrored data immediately creates a fresh
     * snapshot. Tests stay uncached to preserve isolation between fixtures.
     */
    public function cachedChartData(int $rangeDays): array
    {
        if (app()->environment('testing')) {
            return $this->chartData($rangeDays);
        }

        $collections = $this->chartCollections();
        $fetchStamp = (string) ($this->garmin->latestFetch() ?? 'never');
        $key = 'dashboard:chart-data:v6:'.$rangeDays.':'.now()->toDateString().':'.sha1($fetchStamp);
        $cached = Cache::remember($key, now()->addMinutes(10), function () use ($rangeDays, $collections): array {
            $data = $this->buildChartData($rangeDays, ...array_values($collections));

            // Eloquent collections do not belong in the cross-process cache:
            // some stores deliberately unserialize unknown classes as
            // __PHP_Incomplete_Class. Cache only the expensive plain-array
            // chart models, then attach the fresh mirror rows below.
            //
            // deepArray() is the structural backstop underneath the six
            // large collections named here: a chart method that forgets
            // the ->all() on a pluck() (as hrvChart() and timeSeries()
            // once did, see their history) would otherwise
            // slip a Collection into the cached tree, and it would come
            // back as __PHP_Incomplete_Class on the next cache hit. This
            // catches that shape at any depth instead of relying on every
            // chart method getting it right, and every future one too.
            return self::deepArray(array_diff_key($data, array_flip([
                'dayRows', 'sleep', 'hrv', 'activities', 'weather', 'strengthSets', 'latestHrv',
            ])));
        });

        return $cached + $collections + [
            'latestHrv' => $collections['hrv']->sortBy('date')->last(),
        ];
    }

    /**
     * Recursively turns any Arrayable (a Collection, in every case seen so
     * far) nested at any depth into a plain array, leaving scalars, nulls
     * and already-plain arrays untouched.
     *
     * Only cachedChartData() calls this, right before a value enters the
     * database cache store: see the note there for why a surviving
     * Collection is not a cosmetic issue but silent data corruption on the
     * next read. This is a safety net, not a substitute for the ->all()
     * that should already sit at the source: fix the chart method first,
     * and let this catch what the next one misses.
     */
    private static function deepArray(mixed $value): mixed
    {
        if ($value instanceof Arrayable) {
            $value = $value->toArray();
        }

        return is_array($value) ? array_map(self::deepArray(...), $value) : $value;
    }

    /** @return array{dayRows: Collection, sleep: Collection, hrv: Collection, activities: Collection, weather: Collection, strengthSets: Collection} */
    private function chartCollections(): array
    {
        return [
            'dayRows' => $this->garmin->days(self::MAX_RANGE_DAYS),
            'sleep' => $this->garmin->sleep(self::MAX_RANGE_DAYS),
            'hrv' => $this->garmin->hrv(self::MAX_RANGE_DAYS),
            'activities' => $this->garmin->activities(self::ACTIVITY_HISTORY_DAYS),
            'weather' => app(Weather::class)->hours(self::MAX_RANGE_DAYS),
            'strengthSets' => $this->garmin->strengthSets(self::MAX_RANGE_DAYS),
        ];
    }

    /**
     * Chart payload, KPI tiles and window descriptors for one display
     * range. Collections and cumulative models always cover the full
     * mirror history; the range only slices what the charts show, so
     * CTL/ATL keep their warm-up and the answer zone never moves with
     * the switch.
     *
     * @return array{charts: array, kpi: array, meta: array, dayRows: Collection, sleep: Collection, hrv: Collection, activities: Collection, weather: Collection, pmcFull: array, latestHrv: object|null, sleepConsistency: array}
     */
    private function chartData(int $rangeDays): array
    {
        return $this->buildChartData($rangeDays, ...array_values($this->chartCollections()));
    }

    private function buildChartData(
        int $rangeDays,
        Collection $dayRows,
        Collection $sleep,
        Collection $hrv,
        Collection $activities,
        Collection $weather,
        Collection $strengthSets,
    ): array {
        // Inclusive comparison, so the window starts $rangeDays - 1 back:
        // today counts as the first day. Without the -1 a "7 days" choice
        // spans 8 calendar days and the counted descriptors say "8 Tage"
        // while the nominal ones say "7 Tage".
        $since = now()->subDays($rangeDays - 1)->toDateString();
        $lastWeeks = max(self::MIN_WEEKS, (int) ceil($rangeDays / 7));

        $pmcFull = $this->trainingLoad->series($activities, self::MAX_RANGE_DAYS);
        $latestHrv = $hrv->sortBy('date')->last();
        $sleepConsistency = $this->insights->sleepConsistency($sleep);

        $strengthProgression = app(StrengthProgression::class)->weekly($strengthSets, $lastWeeks);

        $charts = [
            'hrv' => $this->hrvChart($hrv, $since),
            'pmc' => self::trimLeadingFlat(self::pmcWindow($pmcFull, $since)),
            'strengthLoad' => $this->weeklyStrengthLoad($activities, $lastWeeks),
            'strengthProgress' => $this->strengthProgressChart($strengthProgression),
            // No card of its own: the HRV card offers resting HR as its
            // second autonomic marker, so the series ships for the overlay.
            'rhr' => $this->timeSeries($dayRows, 'resting_hr', $since),
            'intensity' => $this->weeklyIntensity($dayRows, $lastWeeks),
            // No canvas either: the stimulus weeks feed the deficit hints
            // beside the verdict, server-side only.
            'stimulus' => $this->stimulusLoad($activities, $lastWeeks),
            'trainingEffect' => $this->trainingEffectPoints($activities, $since),
        ];

        // How much of the visible CTL curve is still the model filling up
        // rather than fitness arriving. The chart draws that stretch
        // dashed; at a short range it is usually zero and nothing is
        // marked, because by then the model has long since settled.
        $charts['pmc']['warmup'] = self::pmcWarmupDays($pmcFull['modelStart'] ?? null, $charts['pmc']['dates']);

        // Card descriptors name the window the reader actually sees, so
        // they are recomputed with every range instead of carried over.
        $days = fn (int $n) => trans_choice(':count day|:count days', $n, ['count' => $n]);
        $weeksWord = fn (int $n) => trans_choice(':count week|:count weeks', $n, ['count' => $n]);
        $pmcDays = count($charts['pmc']['dates']);
        $minWeeksNote = $rangeDays < self::MIN_WEEKS * 7 ? ' '.__('(minimum window)') : '';

        /* Garmin publishes the balanced baseline only after it has
           collected enough nights: in this mirror the band starts on the
           18th night, not the first. Undeclared, the empty stretch at the
           left edge reads as missing measurements rather than a baseline
           Garmin had not computed yet. */
        $hrvBandFrom = collect($charts['hrv']['bandLow'])->values()->search(fn ($v) => $v !== null);
        $hrvDesc = match (true) {
            $hrvBandFrom === false => __('Nightly reading · Garmin has no normal band for this range yet'),
            $hrvBandFrom > 0 => __('Nightly reading · normal band only from :date, before that Garmin had no baseline', [
                'date' => Carbon::parse(collect($charts['hrv']['dates'])->values()[$hrvBandFrom])->isoFormat(__('MMM D')),
            ]),
            default => __('Nightly reading against your own normal band'),
        };

        $meta = [
            'desc' => [
                // The dash is only explained while it is on the plot: at a
                // short range the model has long settled and the sentence
                // would point at something the reader cannot see.
                'pmc' => $days($pmcDays).' · '.__('CTL and ATL as lines, TSB as bars')
                    .($charts['pmc']['warmup'] > 0 ? ' · '.__('CTL dashed while the 42-day model is still filling up') : ''),
                'hrv' => $hrvDesc,
                'strengthLoad' => __('Garmin training load from strength and HIIT, :weeks', ['weeks' => $weeksWord(count($charts['strengthLoad']['weeks']))]).$minWeeksNote,
                'strengthProgress' => __('Reps per week from recorded sets, split by exercise category, :weeks', ['weeks' => $weeksWord(count($charts['strengthProgress']['weeks']))]).$minWeeksNote,
                'intensity' => __('Stacked from moderate and double-counted vigorous minutes, :weeks', ['weeks' => $weeksWord(count($charts['intensity']['weeks']))]).$minWeeksNote,
                'trainingEffect' => ($teCount = $charts['trainingEffect']['count']) === 0
                    ? __('Aerobic against anaerobic training effect · no session in this range')
                    : __('Aerobic against anaerobic training effect, :sessions', [
                        'sessions' => trans_choice(':count session|:count sessions', $teCount, ['count' => $teCount]),
                    ]),
            ],
        ];

        $kpi = $this->cardKpis($charts, $charts['pmc'], $latestHrv);

        return [
            'charts' => $charts,
            'kpi' => $kpi,
            'meta' => $meta,
            'dayRows' => $dayRows,
            'sleep' => $sleep,
            'hrv' => $hrv,
            'activities' => $activities,
            'weather' => $weather,
            'weatherInsight' => $this->weatherInsight($sleep, $activities, $dayRows, $hrv, $weather),
            'pmcFull' => $pmcFull,
            'latestHrv' => $latestHrv,
            'sleepConsistency' => $sleepConsistency,
            'strengthProgression' => $this->strengthProgressRows($strengthProgression),
            // Whether the mirror holds any set recording at all, over the
            // widest window rather than the visible one: it decides card
            // presence, and a card must not vanish because the switch
            // moved to a week the sets happen to miss.
            'strengthSetsTotal' => $strengthSets->count(),
        ];
    }

    /**
     * What the athlete's own history says about heat, or nothing.
     *
     * Nothing is the usual answer for a while. Every split stays silent
     * until the mirror holds a fortnight of nights or days, or ten
     * sessions, and when they speak they carry the sample size, because
     * the reader is the one who has to decide whether three warm nights
     * mean anything.
     *
     * @return array{outlook: ?array, sleep: ?array, sessions: ?array, mornings: ?array, hours: int}|null
     */
    private function weatherInsight(Collection $sleep, Collection $activities, Collection $dayRows, Collection $hrv, Collection $weather): ?array
    {
        if ($weather->isEmpty()) {
            return null;
        }
        $service = app(Weather::class);
        $nights = fn (int $n) => trans_choice(':count night|:count nights', $n, ['count' => $n]);
        $days = fn (int $n) => trans_choice(':count day|:count days', $n, ['count' => $n]);
        $sessionWord = fn (int $n) => trans_choice(':count session|:count sessions', $n, ['count' => $n]);
        $n = fn (float $v, int $d = 0) => NumberFormat::format($v, $d);

        /* "Went with", never "caused". The split is a median cut through
           the athlete's own history, and a warm stretch is usually also a
           busy one; nothing here can separate the two, so the sentence
           does not pretend to. The counts ride along so the reader can
           weigh the claim without opening anything. */
        $deep = $service->deepSleepByDewpoint($sleep, $weather);
        $session = $service->sessionStrainByHeat($activities, $weather);
        $fluid = $service->fluidByHeat($dayRows, $weather);
        $mornings = $service->recoveryByHeat($dayRows, $hrv, $weather);

        return [
            'outlook' => $this->outlookNote($service->outlook($weather), $fluid, $n),
            'sleep' => $deep === null ? null : [
                'line' => __('Your muggier nights, dew point above :cut °C, went with a typical :warmValue min of deep sleep. The drier :cool: :coolValue min.', [
                    'cut' => $n($deep['contrast']['cut'], 1),
                    'warmValue' => $n($deep['contrast']['warm']),
                    'cool' => $nights($deep['contrast']['coolN']),
                    'coolValue' => $n($deep['contrast']['cool']),
                ]),
                'caveat' => __('Median over :warm against :cool. Co-occurrence, not cause.', [
                    'warm' => $nights($deep['contrast']['warmN']),
                    'cool' => $nights($deep['contrast']['coolN']),
                ]),
            ],
            /* An immaterial difference gets its own sentence rather than a
               number with a sign. "Nothing to plan around" is the useful
               answer, and burying it in a decimal would invite exactly the
               adjustment the data does not support. */
            'sessions' => $session === null ? null : [
                'line' => $session['material']
                    ? __('Above an apparent :cut °C your pulse in circuit sessions sat :diff bpm :direction, :warmValue against :coolValue.', [
                        'cut' => $n($session['contrast']['cut'], 1),
                        'diff' => $n(abs($session['contrast']['difference'])),
                        'direction' => $session['contrast']['difference'] > 0 ? __('higher') : __('lower'),
                        'warmValue' => $n($session['contrast']['warm']),
                        'coolValue' => $n($session['contrast']['cool']),
                    ])
                    : __('Heat has not reached your circuit sessions so far: :warm above an apparent :cut °C and :cool below it land within :slack bpm of each other.', [
                        'warm' => $sessionWord($session['contrast']['warmN']),
                        'cut' => $n($session['contrast']['cut'], 1),
                        'cool' => $sessionWord($session['contrast']['coolN']),
                        'slack' => $n(abs($session['contrast']['difference'])),
                    ]),
                'caveat' => __('Median over :warm against :cool, 45 to 90 min sessions only. The session varies more than the weather does. The gym follows the temperature outside instead of holding one of its own.', [
                    'warm' => $sessionWord($session['contrast']['warmN']),
                    'cool' => $sessionWord($session['contrast']['coolN']),
                ]),
            ],
            'mornings' => $this->morningNote($mornings, $n, $days),
            'hours' => $weather->count(),
        ];
    }

    /**
     * What the next couple of days are going to feel like, and only where
     * that is worth a sentence.
     *
     * A forecast belongs on a weather app, not here. What earns its place
     * is a day that stands out against this athlete's own year, because
     * that is the day worth moving a session on, and the litre the mirror
     * already knows such a day costs. An ordinary day says so in one
     * clause and stops.
     *
     * @param  array<int, array{offset: int, apparent: float, peak: ?float, outlier: ?string}>  $ahead
     * @param  array{goal: ?array, sweat: ?array}|null  $fluid
     * @return array{line: string, caveat: string}|null
     */
    private function outlookNote(array $ahead, ?array $fluid, callable $n): ?array
    {
        if ($ahead === []) {
            return null;
        }
        $when = fn (int $offset) => $offset === 1 ? __('Tomorrow') : __('The day after');
        $hot = collect($ahead)->firstWhere('outlier', 'high');

        if ($hot === null) {
            return [
                'line' => collect($ahead)
                    ->map(fn (array $d) => __(':when :value °C felt', [
                        'when' => $when($d['offset']),
                        'value' => $n($d['apparent'], 1),
                    ]))
                    ->join(', '),
                'caveat' => __('Neither day stands out against your own year, so nothing here needs planning around.'),
            ];
        }

        $line = $hot['peak'] !== null
            ? __(':when sits in your warmest fifth, :value °C felt and up to :peak.', [
                'when' => $when($hot['offset']),
                'value' => $n($hot['apparent'], 1),
                'peak' => $n($hot['peak'], 1),
            ])
            : __(':when sits in your warmest fifth, :value °C felt.', [
                'when' => $when($hot['offset']),
                'value' => $n($hot['apparent'], 1),
            ]);

        return [
            'line' => $line,
            'caveat' => ($fluid['goal'] ?? null) === null
                ? __('Warmest fifth of the days this mirror holds, from the forecast rather than a measurement.')
                : __('On days like that Garmin asked for :goal ml. Warmest fifth of the days this mirror holds, from the forecast rather than a measurement.', [
                    'goal' => $n($fluid['goal']['warm']),
                ]),
        ];
    }

    /**
     * Whether yesterday's heat can account for this morning's numbers.
     *
     * This note exists to be read on a bad morning, so its most valuable
     * answer is the negative one: if heat does not move these figures,
     * then a resting pulse three beats up is about something else, and
     * training should not be softened for the weather. Both halves are
     * stated whichever way they come out, and the sentence never suggests
     * an adjustment.
     *
     * @param  array{rhr: ?array, hrv: ?array}|null  $mornings
     * @return array{line: string, caveat: string}|null
     */
    private function morningNote(?array $mornings, callable $n, callable $days): ?array
    {
        if ($mornings === null) {
            return null;
        }
        $rhr = $mornings['rhr'];
        $hrv = $mornings['hrv'];
        $moved = array_filter([$rhr, $hrv], fn (?array $part) => $part !== null && $part['material']);
        $sample = $rhr ?? $hrv;

        $caveat = __('Median over :warm against :cool, read the morning after. Co-occurrence, not cause.', [
            'warm' => $days($sample['contrast']['warmN']),
            'cool' => $days($sample['contrast']['coolN']),
        ]);

        if ($moved === []) {
            /* The alibi that does not hold. Worth its own sentence,
               because "it was the heat" is the first thing anyone reaches
               for and this mirror says it was not. */
            return [
                'line' => __('Heat does not show in your mornings: after a warm day your resting pulse and HRV land where they land after a cool one.'),
                'caveat' => $caveat.' '.__('A poor morning after a hot day wants a different explanation.'),
            ];
        }

        $part = function (?array $side, string $unit): ?string {
            if ($side === null || ! $side['material']) {
                return null;
            }
            $up = $side['contrast']['difference'] > 0;

            return $unit === 'bpm'
                ? __(':value bpm :direction', ['value' => NumberFormat::format(abs($side['contrast']['difference'])), 'direction' => $up ? __('higher') : __('lower')])
                : __(':value ms :direction', ['value' => NumberFormat::format(abs($side['contrast']['difference'])), 'direction' => $up ? __('higher') : __('lower')]);
        };
        $pulse = $part($rhr, 'bpm');
        $variability = $part($hrv, 'ms');

        if ($pulse !== null && $variability !== null) {
            $line = __('After a warm day your resting pulse ran :pulse and your HRV :hrv.', ['pulse' => $pulse, 'hrv' => $variability]);
        } elseif ($pulse !== null) {
            $line = __('After a warm day your resting pulse ran :pulse.', ['pulse' => $pulse]);
        } else {
            $line = __('After a warm day your HRV ran :hrv.', ['hrv' => $variability]);
        }

        return ['line' => $line, 'caveat' => $caveat];
    }

    /**
     * The KPI rows re-rendered through the same Blade component the page
     * used, so the fragment the client swaps in can never drift from the
     * server-rendered markup.
     *
     * @param  array<string, array>  $kpi
     * @return array<string, string>
     */
    private function kpiHtml(array $kpi): array
    {
        return collect($kpi)
            ->map(fn (array $items, string $key) => Blade::render(
                '<x-kpi-row :items="$items" data-kpi="'.$key.'" />',
                ['items' => $items],
            ))
            ->all();
    }

    /**
     * Display slice of the PMC arrays. The model always runs over the
     * full history; the range only decides which tail is shown. Slicing
     * the computation instead would restart CTL/ATL from zero inside the
     * history and show a fitness curve climbing off nothing.
     *
     * @param  array{dates: array, ctl: array, atl: array, tsb: array}  $pmc
     * @return array{dates: array, ctl: array, atl: array, tsb: array}
     */
    private static function pmcWindow(array $pmc, string $since): array
    {
        $first = count($pmc['dates']);
        foreach ($pmc['dates'] as $i => $date) {
            if ($date >= $since) {
                $first = $i;
                break;
            }
        }

        $out = [];
        foreach (['dates', 'ctl', 'atl', 'tsb'] as $key) {
            $out[$key] = array_slice($pmc[$key], $first);
        }

        return $out;
    }

    /**
     * The client's slice of the chart set: the six drawn cards plus the
     * series the curated overlays pick (see OVERLAY_SERIES in app.js);
     * resting HR rides along for the HRV card's second marker. The
     * stimulus weeks stay server-side with the deficit hints.
     *
     * @param  array<string, array>  $charts
     */
    public function surfaceCharts(array $charts): array
    {
        $keys = ['hrv', 'pmc', 'strengthLoad', 'strengthProgress', 'intensity', 'trainingEffect', 'rhr'];

        return array_intersect_key($charts, array_flip($keys));
    }

    /**
     * Footer KPIs per chart card: the two or three numbers that answer
     * "where do I stand right now" without reading the curve. Everything
     * comes from series the page already loads, so this costs no query.
     */
    private function cardKpis(array $charts, array $pmc, $latestHrv): array
    {
        $n = fn (?float $v, int $dec = 0) => $v === null ? '–' : NumberFormat::format($v, $dec);
        $signed = fn (?float $v, int $dec = 0) => $v === null ? '–' : ($v > 0 ? '+' : '').NumberFormat::format($v, $dec);

        $tsb = self::lastOf($pmc['tsb']);
        $load = $charts['strengthLoad']['load'];
        $mins = $charts['intensity']['minutes'];

        /* Counted off the collapsed points, so a coordinate that stands for
           seventeen walks counts seventeen times. */
        $tePoints = collect($charts['trainingEffect']['groups'])->flatMap(fn (array $g) => $g['points']);
        $teTotal = $charts['trainingEffect']['count'];
        $te = fn (callable $test) => $tePoints->filter($test)->sum('n');

        $of = fn (int $total) => __('of :total', ['total' => $total]);

        return [
            'pmc' => [
                ['label' => __('Fitness (CTL)'), 'value' => $n(self::lastOf($pmc['ctl'])), 'tone' => 'blue'],
                ['label' => __('Fatigue (ATL)'), 'value' => $n(self::lastOf($pmc['atl'])), 'tone' => 'orange'],
                // Blue on both sides, like the bars it summarises. A tone
                // names the series a tile belongs to; the red it used to
                // switch to below zero named a status instead, and the
                // status it implied is wrong: a negative balance is what a
                // build block looks like, not a warning. The sign is
                // already written into the value.
                ['label' => __('Form (TSB)'), 'value' => $signed($tsb), 'tone' => 'blue'],
            ],
            'hrv' => [
                ['label' => __('Last night'), 'value' => $n(self::lastOf($charts['hrv']['lastNight'])), 'unit' => 'ms', 'tone' => 'blue'],
                ['label' => __('7-day average'), 'value' => $n($latestHrv?->weekly_avg), 'unit' => 'ms', 'tone' => 'blue'],
                ['label' => __('Normal band'), 'value' => $n($latestHrv?->baseline_balanced_low).'–'.$n($latestHrv?->baseline_balanced_upper), 'unit' => 'ms'],
            ],
            // "This week" is the running week and therefore incomplete by
            // definition; saying so keeps it from reading as a weak week.
            'strengthLoad' => [
                ['label' => __('This week, running'), 'value' => $n(self::lastOf($load)), 'tone' => 'blue'],
                ['label' => __('Previous week'), 'value' => $n(self::lastOf($load, 1))],
                ['label' => __('Peak over :weeks wk', ['weeks' => count($load)]), 'value' => $n(collect($load)->max())],
            ],
            'intensity' => [
                ['label' => __('This week, running'), 'value' => $n(self::lastOf($mins)), 'unit' => 'min', 'tone' => 'blue'],
                ['label' => __('Previous week'), 'value' => $n(self::lastOf($mins, 1)), 'unit' => 'min'],
                ['label' => __('WHO corridor'), 'value' => $n((float) $charts['intensity']['goal']).'–'.$n((float) $charts['intensity']['goalUpper']), 'unit' => 'min'],
            ],
            /* Counted against the Firstbeat steps the axes are drawn in, so
               the tiles and the plot answer in the same units. "No stimulus"
               is not a failure: it is what a walk is supposed to score. */
            'trainingEffect' => [
                ['label' => __('Aerobic improving'), 'value' => $te(fn (array $p) => $p['x'] >= 3), 'unit' => $of($teTotal), 'tone' => 'blue'],
                ['label' => __('Anaerobic maintaining or better'), 'value' => $te(fn (array $p) => $p['y'] >= 2), 'unit' => $of($teTotal)],
                ['label' => __('No stimulus'), 'value' => $te(fn (array $p) => $p['x'] < 1 && $p['y'] < 1), 'unit' => $of($teTotal)],
            ],
        ];
    }

    /** Nth-from-last non-null value of a series (0 = last). */
    private static function lastOf(iterable $values, int $back = 0): float|int|null
    {
        $clean = collect($values)->filter(fn ($v) => $v !== null)->values();

        return $clean->get($clean->count() - 1 - $back);
    }

    /**
     * Plain arrays only, never the Collection pluck() returns: this model
     * rides inside cachedChartData()'s cached payload, see the note there.
     */
    private function hrvChart(Collection $hrv, string $since): array
    {
        $sorted = $hrv->filter(fn ($r) => $r->date >= $since)->sortBy('date')->values();

        return [
            'dates' => $sorted->pluck('date')->all(),
            'lastNight' => $sorted->pluck('last_night_avg')->all(),
            'weekly' => $sorted->pluck('weekly_avg')->all(),
            'bandLow' => $sorted->pluck('baseline_balanced_low')->all(),
            'bandUp' => $sorted->pluck('baseline_balanced_upper')->all(),
        ];
    }

    /**
     * Continuous ISO week keys from $from to $to inclusive.
     *
     * Grouping by week and reading back array_keys() drops every week
     * without a row, which silently compresses the x axis: two weeks off
     * then a normal week reads as three normal weeks side by side. The
     * grid keeps every slot so the axis stays a time axis.
     *
     * @return list<string>
     */
    private static function weekGrid(string $from, string $to): array
    {
        $week = fn (string $key) => (new \DateTimeImmutable)
            ->setISODate((int) substr($key, 0, 4), (int) substr($key, 6))
            ->setTime(0, 0);

        $keys = [];
        for ($cursor = $week($from), $end = $week($to); $cursor <= $end; $cursor = $cursor->modify('+7 days')) {
            $keys[] = $cursor->format('o-\WW');
        }

        return $keys;
    }

    /**
     * Weekly Garmin training load of strength/HIIT sessions. Set tonnage
     * would be the natural metric, but Garmin delivers no weights for
     * watch-tracked circuit sessions, so training load is the reliable proxy.
     *
     * The running week always occupies the last slot, even before its
     * first session: without it "Diese Woche" silently reports the last
     * week that happened to have one.
     */
    private function weeklyStrengthLoad(Collection $activities, int $lastWeeks): array
    {
        $strengthTypes = ['hiit', 'strength_training', 'indoor_cardio', 'fitness_equipment'];
        $weeks = [];
        foreach ($activities as $a) {
            if (! $a->training_load || ! $a->date || ! in_array($a->type_key, $strengthTypes, true)) {
                continue;
            }
            $week = date('o-\WW', strtotime($a->date));
            // Rounded per session, matching the detail panel that lists the
            // same sessions; see strengthSessionsDetail().
            $weeks[$week] = ($weeks[$week] ?? 0) + (int) round($a->training_load);
        }
        if (! $weeks) {
            return ['weeks' => [], 'load' => [], 'runningIndex' => null];
        }
        ksort($weeks);

        // A week without a session is a zero, not an absent category.
        $thisWeek = now()->format('o-\WW');
        $filled = [];
        foreach (self::weekGrid((string) array_key_first($weeks), max((string) array_key_last($weeks), $thisWeek)) as $key) {
            $filled[$key] = $weeks[$key] ?? 0;
        }
        $filled = array_slice($filled, -$lastWeeks, preserve_keys: true);
        $keys = array_keys($filled);

        return [
            'weeks' => $keys,
            'load' => array_values($filled),
            'runningIndex' => self::indexOfWeek($keys, $thisWeek),
        ];
    }

    /**
     * The stacked progression chart: weekly reps of the biggest exercise
     * categories, everything smaller folded into one Other series.
     *
     * Reps for every series, deliberately: this mirror records a weight
     * on almost no set (see StrengthProgression), so reps are the one
     * volume unit every category honestly shares, and a stack must never
     * add kilograms to repetitions. The kilogram side of a weighted
     * category lives in the rows beside the chart instead.
     */
    private function strengthProgressChart(array $model): array
    {
        $top = array_slice($model['categories'], 0, self::PROGRESS_TOP_CATEGORIES);
        $rest = array_slice($model['categories'], self::PROGRESS_TOP_CATEGORIES);

        $series = array_map(fn (array $c) => [
            'label' => StrengthProgression::label($c['key']),
            'reps' => $c['reps'],
            'other' => false,
        ], $top);

        if ($rest !== []) {
            $sum = array_fill(0, count($model['weeks']), 0);
            foreach ($rest as $c) {
                foreach ($c['reps'] as $i => $reps) {
                    $sum[$i] += $reps;
                }
            }
            $series[] = ['label' => __('Other'), 'reps' => $sum, 'other' => true];
        }

        return [
            'weeks' => $model['weeks'],
            'series' => $series,
            'runningIndex' => $model['runningIndex'],
        ];
    }

    /**
     * The row list under the progression chart, one row per named
     * category of the stack, so chart and rows can never disagree about
     * what the main categories are.
     *
     * Weighted categories answer in kilograms (current top, heaviest
     * set, the stagnation reading), weightless ones in reps. A category
     * never shows both units in one breath, and a mirror without a
     * single recorded weight says so in one sentence instead of printing
     * a column of dashes.
     */
    private function strengthProgressRows(array $model): array
    {
        return [
            'sessions' => $model['sessions'],
            'anyWeight' => $model['anyWeight'],
            'rows' => array_map(fn (array $c) => [
                'label' => StrengthProgression::label($c['key']),
                'unclassified' => $c['key'] === 'UNKNOWN',
                'weighted' => $c['weighted'],
                'currentTopKg' => $c['currentTopKg'],
                'bestSetKg' => $c['bestSetKg'],
                'lastFullWeekReps' => $c['lastFullWeekReps'],
                'bestWeekReps' => $c['bestWeekReps'],
                'stagnation' => $c['stagnation'],
            ], array_slice($model['categories'], 0, self::PROGRESS_TOP_CATEGORIES)),
        ];
    }

    /**
     * Weekly intensity minutes against the WHO corridor, split into the
     * two kinds of minute the total is made of.
     *
     * The plotted total is a weighted one: vigorous minutes count double,
     * so a 300-minute week can be 300 easy minutes or 150 hard ones. Those
     * are different training weeks with the same bar, which is why the bar
     * carries its own composition instead of a single number.
     *
     * Days the mirror never filled stay null instead of counting as zero:
     * the first weeks on record have no intensity columns at all, and a
     * zero bar there claims a week of inactivity that never happened.
     */
    private function weeklyIntensity(Collection $days, int $lastWeeks): array
    {
        $empty = ['moderate' => 0, 'vigorous' => 0];

        $weeks = [];
        $measured = [];
        foreach ($days as $d) {
            $week = date('o-\WW', strtotime($d->date));
            $weeks[$week] ??= $empty;
            if ($d->intensity_moderate_min === null && $d->intensity_vigorous_min === null) {
                continue;
            }
            $measured[$week] = true;
            $weeks[$week]['moderate'] += (int) $d->intensity_moderate_min;
            // WHO/Garmin counting: vigorous minutes count double. Doubling
            // here keeps the two segments adding up to the plotted total.
            $weeks[$week]['vigorous'] += 2 * (int) $d->intensity_vigorous_min;
        }
        if (! $weeks) {
            return [
                'weeks' => [], 'minutes' => [], 'moderate' => [], 'vigorous' => [],
                'goal' => 150, 'goalUpper' => 300, 'runningIndex' => null,
            ];
        }
        ksort($weeks);

        $thisWeek = now()->format('o-\WW');
        $filled = [];
        foreach (self::weekGrid((string) array_key_first($weeks), max((string) array_key_last($weeks), $thisWeek)) as $key) {
            $filled[$key] = isset($measured[$key]) ? ($weeks[$key] ?? $empty) : null;
        }
        $filled = array_slice($filled, -$lastWeeks, preserve_keys: true);
        $keys = array_keys($filled);
        $rows = array_values($filled);

        $part = fn (?string $which) => array_map(
            fn (?array $w) => match (true) {
                $w === null => null,
                $which === null => $w['moderate'] + $w['vigorous'],
                default => $w[$which],
            },
            $rows
        );

        return [
            'weeks' => $keys,
            'minutes' => $part(null),
            'moderate' => $part('moderate'),
            // Already doubled: what the guideline credits, not raw minutes.
            'vigorous' => $part('vigorous'),
            // The WHO states a corridor, not a floor: 150 to 300 minutes
            // of moderate activity a week, vigorous minutes counting double.
            'goal' => 150,
            'goalUpper' => 300,
            'runningIndex' => self::indexOfWeek($keys, $thisWeek),
        ];
    }

    /** Position of the running week in a week grid, null when outside it. */
    private static function indexOfWeek(array $keys, string $week): ?int
    {
        $i = array_search($week, $keys, true);

        return $i === false ? null : (int) $i;
    }

    /**
     * Drop the leading stretch where the model has no history yet. The PMC
     * window is a fixed 120 days, but the Garmin mirror starts later, so
     * CTL and ATL sit at exactly zero until the first recorded activity:
     * today that is 63 of 120 days, half a plot of flat line that says
     * nothing and squeezes the real curve into the right-hand side.
     *
     * @param  array{dates: array, ctl: array, atl: array, tsb: array}  $pmc
     * @return array{dates: array, ctl: array, atl: array, tsb: array}
     */
    private static function trimLeadingFlat(array $pmc): array
    {
        $first = 0;
        foreach (array_keys($pmc['dates']) as $i) {
            if ($pmc['ctl'][$i] > 0 || $pmc['atl'][$i] > 0) {
                $first = $i;
                break;
            }
        }
        // Keep a couple of empty days so the curve visibly rises off zero
        // instead of starting mid-air against the axis.
        $first = max(0, $first - 2);

        foreach (['dates', 'ctl', 'atl', 'tsb'] as $key) {
            $pmc[$key] = array_slice($pmc[$key], $first);
        }

        return $pmc;
    }

    /**
     * Number of leading days in the visible PMC window that still fall
     * inside the CTL warm-up, i.e. within one 42-day time constant of the
     * first day the model saw load. An EWMA seeded with zero has reached
     * 63 % of its sustained level by then; before that its rise is the
     * model catching up, and a fitness curve must not sell that as a
     * fitness gain. Nothing recorded yet means nothing to mark.
     *
     * @param  array<int, string>  $dates
     */
    private static function pmcWarmupDays(?string $modelStart, array $dates): int
    {
        if ($modelStart === null) {
            return 0;
        }

        $until = Carbon::parse($modelStart)->addDays((int) TrainingLoad::CTL_TC)->toDateString();

        return count(array_filter($dates, fn (string $d) => $d < $until));
    }

    /**
     * Plain arrays only, never the Collection pluck() returns: this model
     * rides inside cachedChartData()'s cached payload, see the note there.
     * Backs vo2, rhr, endurance and sleepScore.
     *
     * @return array{dates: array<int, string>, values: array<int, mixed>}
     */
    private function timeSeries(Collection $rows, string $column, string $since): array
    {
        $sorted = $rows->filter(fn ($r) => $r->{$column} !== null && $r->date >= $since)
            ->sortBy('date')->values();

        return ['dates' => $sorted->pluck('date')->all(), 'values' => $sorted->pluck($column)->all()];
    }

    /**
     * Which kind of stimulus the weekly load was actually spent on.
     *
     * A HYROX week is half running and half stations, and the way that
     * balance goes wrong is invisible in a single load total: a week of
     * 800 built from eight circuit sessions and a week of 800 built from
     * four runs and four circuit sessions are the same bar on every other
     * card here.
     *
     * Combo sessions get their own segment rather than joining either
     * side. They are the race-specific stimulus, they carry more load in
     * this mirror than all the pure runs together, and folding them into
     * "running" or "strength" would erase whichever half it kept.
     */
    private function stimulusLoad(Collection $activities, int $lastWeeks): array
    {
        $empty = ['run' => 0, 'strength' => 0, 'combo' => 0, 'other' => 0];

        $weeks = [];
        foreach ($activities as $a) {
            if (! $a->training_load || ! $a->date) {
                continue;
            }
            $key = Stimulus::bucket($a->type_key);
            $week = date('o-\WW', strtotime($a->date));
            $weeks[$week] ??= $empty;
            $weeks[$week][$key] += (int) round($a->training_load);
        }
        if (! $weeks) {
            return [
                'weeks' => [], 'run' => [], 'strength' => [], 'combo' => [],
                'other' => [], 'total' => [], 'runShare' => [], 'runningIndex' => null,
            ];
        }
        ksort($weeks);

        // A week without a session is a real zero here, not a gap: the
        // mirror records every activity, so nothing was trained.
        $thisWeek = now()->format('o-\WW');
        $filled = [];
        foreach (self::weekGrid((string) array_key_first($weeks), max((string) array_key_last($weeks), $thisWeek)) as $key) {
            $filled[$key] = $weeks[$key] ?? $empty;
        }
        $filled = array_slice($filled, -$lastWeeks, preserve_keys: true);
        $rows = array_values($filled);

        $part = fn (string $which) => array_map(fn (array $w) => $w[$which], $rows);
        $total = array_map(fn (array $w) => array_sum($w), $rows);

        return [
            'weeks' => array_keys($filled),
            'run' => $part('run'),
            'strength' => $part('strength'),
            'combo' => $part('combo'),
            'other' => $part('other'),
            'total' => $total,
            /* Share of the week that ran, combo sessions counted in: they
               are half running by construction. Null on an empty week,
               never zero, because "trained nothing" and "trained, none of
               it running" are different weeks and only one is a problem. */
            'runShare' => array_map(
                fn (array $w, int $sum) => $sum === 0 ? null : (int) round(100 * ($w['run'] + $w['combo']) / $sum),
                $rows,
                $total
            ),
            'runningIndex' => self::indexOfWeek(array_keys($filled), $thisWeek),
        ];
    }

    /**
     * Every session as a point in the two-axis stimulus space Garmin
     * scores it in: how much it asked of the aerobic system against how
     * much it asked of the anaerobic one.
     *
     * Both values are measured on every activity in this mirror, so a
     * zero is a reading ("this asked nothing of that system") and not a
     * gap. Only a genuinely absent value is dropped.
     */
    private function trainingEffectPoints(Collection $activities, string $since): array
    {
        $points = [];
        foreach ($activities as $a) {
            if ($a->aerobic_te === null || $a->anaerobic_te === null || $a->date < $since) {
                continue;
            }
            $points[] = [
                'x' => round((float) $a->aerobic_te, 1),
                'y' => round((float) $a->anaerobic_te, 1),
                'date' => $a->date,
                'bucket' => Stimulus::bucket($a->type_key),
                'load' => $a->training_load === null ? null : (int) round($a->training_load),
            ];
        }

        /* Grouped by the same four kinds the load card splits its weeks
           into, and drawn in the same four colours. One encoding to learn
           instead of two, and the reader can carry a question from one
           card to the other: the load card says how much of each kind,
           this one says what each kind actually asked of the body. */
        $groups = [];
        foreach ($points as $p) {
            $groups[$p['bucket']][] = $p;
        }
        // Densest kind first, so the long tail of one-off kinds draws on
        // top of the cluster instead of disappearing underneath it.
        uasort($groups, fn (array $a, array $b) => count($b) <=> count($a));

        $labels = self::stimulusLabels();

        return [
            'groups' => array_values(array_map(
                fn (array $g, string $bucket) => [
                    'bucket' => $bucket,
                    'label' => $labels[$bucket],
                    'points' => self::collapseDuplicatePoints($g),
                ],
                $groups,
                array_keys($groups)
            )),
            'count' => count($points),
        ];
    }

    /**
     * Display names of the four stimulus kinds, shared by both cards.
     *
     * A method rather than a constant because the labels are translated.
     *
     * @return array<string, string>
     */
    private static function stimulusLabels(): array
    {
        return [
            'run' => __('Running'),
            'combo' => __('Combo (run + stations)'),
            'strength' => __('Strength & HIIT'),
            'other' => __('Other'),
        ];
    }

    /**
     * Sessions that scored identically become one point carrying a count.
     *
     * Garmin reports both effects to one decimal, so sessions of the same
     * kind land on each other constantly: half the walks in this mirror
     * sit on a single coordinate. Drawn as separate markers they are one
     * dot claiming to be one walk, and the densest part of the plot is
     * the part that understates itself most.
     */
    private static function collapseDuplicatePoints(array $points): array
    {
        $byCoord = [];
        foreach ($points as $p) {
            $byCoord[$p['x'].'/'.$p['y']][] = $p;
        }

        return array_values(array_map(fn (array $same): array => [
            'x' => $same[0]['x'],
            'y' => $same[0]['y'],
            'n' => count($same),
            'date' => max(array_column($same, 'date')),
        ], $byCoord));
    }
}
