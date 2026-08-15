<?php

namespace App\View\Components;

use App\Garmin\MuscleFreshness;
use App\View\Heartbeat;
use Illuminate\Support\Carbon;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Schematic body map: anterior + posterior silhouettes with muscle zones
 * colored by load (inverse freshness) and four body-system markers.
 *
 * Polygon data: MIT-licensed muscle polygons from the body-highlighter
 * project family (https://github.com/lahaxearnaud/body-highlighter),
 * converted to JSON in resources/data/body-polygons.json.
 */
class BodyMap extends Component
{
    /** Ramp steps live as CSS custom properties (--load-0..9) so light
     *  and dark mode carry their own scale; see resources/css/app.css. */
    private const RAMP_STEPS = 10;

    /** How hard the ramp bends toward the low end; see rampCurve(). */
    private const RAMP_GAMMA = 0.55;

    /** Below this load a zone reads as recovered and stays off the ramp. */
    private const NEUTRAL_BELOW = 5;

    /** Below this freshness a zone earns the second channel on the map. */
    private const SPENT_BELOW = 20;

    /** A gap this long is a pattern worth naming, not a rest day. */
    private const QUIET_AFTER_DAYS = 10;

    /** Distance between the silhouette box and its caption pills. */
    private const PILL_GAP = 5;

    /** Air beyond the widest pill, so a wide glyph never touches the edge. */
    private const PILL_CLEARANCE = 2;

    public array $sides;

    /** Panel payload per zone: label, freshness fields and coach advice. */
    public array $zones;

    /** Zone keys with load history, strongest load first. */
    public array $ranked;

    /** Zone keys Garmin never reported load for; never part of the ranking. */
    public array $unknown;

    /**
     * Zone keys as Garmin spells them, with the words the panel shows.
     * Filled in the constructor from the domain's one label source, so
     * map, teaser and deficit hints cannot name the same zone apart.
     */
    public array $zoneLabels;

    /**
     * The five body-system markers on the anterior silhouette. `fx`/`fy`
     * are fractions of the figure box, `capdy` lifts the caption off the
     * marker so no two leader lines cross. Kept here rather than in the
     * template because the caption widths decide how much room the
     * viewBox has to reserve beside the figure.
     */
    public array $findings;

    public array $statusLabels;

    /** The lens control: freshness now, or accumulated volume per window. */
    public array $lenses;

    /** Weekly set corridor the zone detail reports against. */
    public array $corridor;

    /** One reading over the map, or null while no zone has history. */
    public ?array $story;

    private array $zoneAlias = [
        'LEFT_SOLEUS' => 'CALVES',
        'RIGHT_SOLEUS' => 'CALVES',
        'ABDUCTOR' => 'ABDUCTORS',
    ];

    public function __construct(
        public array $freshness = [],
        public array $systems = [],
        public array $volumeCeiling = [],
        public array $symptoms = [],
        /** Pulse and its swing for the heart inside the solid; see
         *  Heartbeat. Null keeps that heart still, which is the honest
         *  answer when nothing was measured. */
        public ?Heartbeat $beat = null,
    ) {
        $this->zoneLabels = MuscleFreshness::zoneLabels();

        $this->findings = [
            'heart' => ['name' => __('Heart'), 'fx' => 0.565, 'fy' => 0.240, 'side' => 'right', 'capdy' => -14],
            'head' => ['name' => __('Sleep'), 'fx' => 0.500, 'fy' => 0.030, 'side' => 'right', 'capdy' => 2],
            'lungs' => ['name' => __('Breathing'), 'fx' => 0.415, 'fy' => 0.215, 'side' => 'left', 'capdy' => -12],
            'core' => ['name' => __('Autonomic'), 'fx' => 0.500, 'fy' => 0.395, 'side' => 'left', 'capdy' => 10],
            'metabolism' => ['name' => __('Metabolism'), 'fx' => 0.535, 'fy' => 0.470, 'side' => 'right', 'capdy' => 12],
        ];

        $this->statusLabels = [
            'good' => __('OK'),
            'warning' => __('Watch'),
            'serious' => __('Caution'),
            'critical' => __('Critical'),
        ];

        $raw = json_decode(file_get_contents(resource_path('data/body-polygons.json')), true);

        $this->sides = [];
        foreach (['anterior', 'posterior'] as $side) {
            $entries = [];
            $minX = $minY = INF;
            $maxX = $maxY = -INF;
            foreach ($raw[$side] as $entry) {
                foreach ($entry['polygons'] as $poly) {
                    foreach (preg_split('/\s+/', trim($poly)) as $i => $coord) {
                        $v = (float) $coord;
                        if ($i % 2 === 0) {
                            $minX = min($minX, $v);
                            $maxX = max($maxX, $v);
                        } else {
                            $minY = min($minY, $v);
                            $maxY = max($maxY, $v);
                        }
                    }
                }
                // Aliased duplicates (left/right soleus) merge into their
                // zone so every zone is exactly one control per silhouette:
                // one tab stop, one hover surface, one selection contour.
                $zone = $this->zoneAlias[$entry['muscle']] ?? $entry['muscle'];
                if (isset($entries[$zone])) {
                    $entries[$zone]['polygons'] = array_merge($entries[$zone]['polygons'], $entry['polygons']);
                } else {
                    $entries[$zone] = ['zone' => $zone, 'polygons' => $entry['polygons']];
                }
            }
            $pad = 4;
            $this->sides[$side] = [
                'entries' => array_values($entries),
                'figure' => [
                    'x' => $minX - $pad,
                    'y' => $minY - $pad,
                    'w' => $maxX - $minX + 2 * $pad,
                    'h' => $maxY - $minY + 2 * $pad,
                ],
            ];
        }

        // Both silhouettes are drawn from one unit height and one unit
        // figure width, so they stay the same size as each other. Only the
        // anterior reserves room for its caption pills: the posterior has
        // none and would otherwise pay for a margin it never uses, which
        // is what the whole map is scaled down by. The template sizes the
        // two columns by `unitWidth`, which is what keeps the one scale
        // across both boxes.
        $maxH = max(array_map(fn ($s) => $s['figure']['h'], $this->sides));
        $figW = max(array_map(fn ($s) => $s['figure']['w'], $this->sides));
        foreach ($this->sides as $side => $data) {
            $f = $data['figure'];
            $unitW = $figW + ($side === 'anterior' ? 2 * $this->pillMargin() : 0);
            $extraY = ($maxH - $f['h']) / 2;
            $extraX = ($unitW - $f['w']) / 2;
            $this->sides[$side]['unitWidth'] = round($unitW, 1);
            $this->sides[$side]['viewBox'] = sprintf(
                '%s %s %s %s',
                round($f['x'] - $extraX, 1),
                round($f['y'] - $extraY, 1),
                round($unitW, 1),
                round($maxH, 1),
            );
            // The same box without the caption margin, for the thumbnail in
            // the sheet header: it draws no pills, and reserving room for
            // them there would shrink the silhouette to pay for empty air.
            $this->sides[$side]['figureBox'] = sprintf(
                '%s %s %s %s',
                round($f['x'] - ($figW - $f['w']) / 2, 1),
                round($f['y'] - $extraY, 1),
                round($figW, 1),
                round($maxH, 1),
            );
        }

        $this->corridor = config('muscle_map.weekly_set_corridor');
        $this->zones = $this->buildZones();
        [$this->ranked, $this->unknown] = $this->splitZones();
        $this->lenses = $this->buildLenses();
        $this->story = $this->buildStory();
    }

    /**
     * Centre of a zone's polygons, where its symptom marker sits. The
     * average of the points rather than the bounding-box middle: a
     * C-shaped zone (the obliques wrap the trunk) has its box centre
     * outside the muscle, and the marker would float beside the body.
     *
     * @param  list<string>  $polygons
     * @return array{x: float, y: float}
     */
    public static function polygonCentre(array $polygons): array
    {
        $sumX = $sumY = 0.0;
        $n = 0;
        foreach ($polygons as $poly) {
            $coords = preg_split('/\s+/', trim($poly));
            for ($i = 0; $i + 1 < count($coords); $i += 2) {
                $sumX += (float) $coords[$i];
                $sumY += (float) $coords[$i + 1];
                $n++;
            }
        }

        return $n > 0
            ? ['x' => round($sumX / $n, 1), 'y' => round($sumY / $n, 1)]
            : ['x' => 0.0, 'y' => 0.0];
    }

    /**
     * The lens control over the map. Freshness answers "what can I train
     * tonight", the volume windows answer "where has my training gone",
     * and they are genuinely different readings: freshness decays, volume
     * does not. Naming the window on screen is what makes the colour
     * depth readable at all.
     *
     * @return list<array{key: string, name: string, desc: string}>
     */
    private function buildLenses(): array
    {
        $lenses = [[
            'key' => 'freshness',
            'name' => __('Freshness'),
            'desc' => __('What is left in the tank right now, decayed per zone.'),
        ]];
        foreach (MuscleFreshness::WINDOWS as $span) {
            $lenses[] = [
                'key' => (string) $span,
                'name' => __(':days days', ['days' => $span]),
                'desc' => __('Accumulated load of the last :days days, against your loudest zone in that window.', ['days' => $span]),
            ];
        }

        return $lenses;
    }

    /**
     * One sentence over the map: what loaded, and what is waiting. The
     * card is the centre of the training tab, and a figure without a
     * reading is an anatomy poster. Everything here is drawn from the same
     * numbers the zones carry, so the line cannot claim more than the map.
     *
     * @return array{lead: string, follow: string|null, tone: string}|null
     */
    private function buildStory(): ?array
    {
        $withData = array_filter($this->zones, fn (array $z) => $z['hasData']);
        if ($withData === []) {
            return null;
        }

        // Loaded zones first: they are what a training decision turns on.
        $loaded = array_filter($withData, fn (array $z) => $z['freshness'] < 75);
        uasort($loaded, fn (array $a, array $b) => $a['freshness'] <=> $b['freshness']);

        // Nothing loaded is a state with its own message, not an empty
        // card: the muscles' green light for a hard day. Whether the day
        // allows one is the readiness verdict's call (the watch and the
        // morning briefing carry it), so the line stays on the muscle
        // level instead of clearing the whole day and contradicting a
        // "take it easy" morning.
        if ($loaded === []) {
            return [
                'lead' => __('Every zone with data is recovered.'),
                'follow' => __('The muscles set no limit on intensity today.'),
                'tone' => 'ready',
            ];
        }

        // Values, not the zone-keyed slice: the labels are read by
        // position below, and preserved keys would leave $names[0] unset.
        $names = array_map(fn (array $z) => $z['label'], array_slice(array_values($loaded), 0, 2));
        $lead = count($names) === 2
            ? __(':first and :second carry the most load right now.', ['first' => $names[0], 'second' => $names[1]])
            : __(':zone carries the most load right now.', ['zone' => $names[0]]);

        // The session behind it, when the attribution is clear enough to
        // name one: it is what lets the athlete sanity-check the mapping.
        $top = array_values($loaded)[0];
        $session = $top['windows'][MuscleFreshness::WINDOWS[0]]['sessions'][0] ?? null;
        if ($session !== null && $session['share'] >= 25) {
            $lead = $lead.' '.__('Mostly from :session.', ['session' => $session['label']]);
        }

        return ['lead' => $lead, 'follow' => $this->quietestZone($withData), 'tone' => 'loaded'];
    }

    /**
     * The zone that has been waiting longest, when the wait is long enough
     * to be a pattern rather than a rest day.
     */
    private function quietestZone(array $withData): ?string
    {
        uasort($withData, fn (array $a, array $b) => $b['daysSince'] <=> $a['daysSince']);
        $quietest = array_values($withData)[0];

        if ($quietest['daysSince'] < self::QUIET_AFTER_DAYS) {
            return null;
        }

        return __(':zone has been without a stimulus for :days days.', [
            'zone' => $quietest['label'],
            'days' => $quietest['daysSince'],
        ]);
    }

    /**
     * Width of a caption pill, estimated from the glyph count of its two
     * lines. The template draws the pill with this number and the viewBox
     * reserves room with the same one, so the drawn box and the reserved
     * box cannot drift apart.
     */
    public function captionWidth(string $name, string $statusWord): float
    {
        return max(mb_strlen($name) * 3.9, mb_strlen($statusWord) * 3.2) + 10;
    }

    /** Left edge of a caption pill, on whichever side of the figure it sits. */
    public function captionX(array $finding, float $width): float
    {
        $fig = $this->sides['anterior']['figure'];

        return $finding['side'] === 'right'
            ? $fig['x'] + $fig['w'] + self::PILL_GAP
            : $fig['x'] - self::PILL_GAP - $width;
    }

    /**
     * Room the caption pills need beside the silhouette. Derived from the
     * labels instead of guessed: the clip happens in figure units, so a
     * fixed margin that is too narrow cuts the widest pill off at every
     * screen size. The longest status word counts for every pill, so the
     * day's readings can never change the scale of the figure, and the
     * margin stays symmetric so the figure keeps sitting centred above
     * its caption.
     */
    private function pillMargin(): float
    {
        $longestStatus = array_reduce(
            $this->statusLabels,
            fn (string $carry, string $word) => mb_strlen($word) > mb_strlen($carry) ? $word : $carry,
            '',
        );

        $widest = max(array_map(
            fn (array $finding) => $this->captionWidth($finding['name'], $longestStatus),
            $this->findings,
        ));

        return self::PILL_GAP + $widest + self::PILL_CLEARANCE;
    }

    /** Fill color for a zone: neutral when fresh, sequential blue with load. */
    public function fill(string $zone): string
    {
        $data = $this->freshness[$zone] ?? null;
        if (! $data) {
            return 'var(--map-neutral)';
        }

        return $this->rampFill(100 - $data['freshness']);
    }

    /**
     * The ramp step for a 0..100 load reading, shared by both lenses so
     * one colour means one amount of load whichever window is on screen.
     */
    private function rampFill(float $load): string
    {
        $load = max(0.0, min(100.0, $load));
        if ($load < self::NEUTRAL_BELOW) {
            return 'var(--map-neutral)';
        }
        // Buckets over the loaded range, but not equal-width ones. Dividing
        // by RAMP_STEPS - 1 would stretch nine buckets across the whole
        // range and leave the tenth reachable only at exactly 100, which
        // made the darkest colour a synonym for "hit the calibration
        // ceiling".
        $idx = min(self::RAMP_STEPS - 1, (int) floor(self::rampCurve($load) * self::RAMP_STEPS));

        return "var(--load-{$idx})";
    }

    /**
     * Where a 0..100 load sits on the ramp, as 0..1.
     *
     * Load is measured against the athlete's hardest zone-day of the last
     * ninety days, so 100 is an outlier by construction and an ordinary
     * day lands under 40. Spread linearly, that put every normal reading
     * in the bottom four steps and left six colours unreachable. The
     * exponent bends the ramp so the range that actually occurs gets the
     * resolution, without touching the order or making the mapping depend
     * on the day: one colour still means one load, today and next month.
     *
     * Public because the legend has to place its ticks on the same curve.
     */
    public static function rampCurve(float $load): float
    {
        $load = max(0.0, min(100.0, $load));
        if ($load <= self::NEUTRAL_BELOW) {
            return 0.0;
        }

        return (($load - self::NEUTRAL_BELOW) / (100 - self::NEUTRAL_BELOW)) ** self::RAMP_GAMMA;
    }

    /**
     * One fill per lens. Freshness paints what is left in the tank and is
     * calibrated against the athlete's hardest zone-day; the volume lenses
     * paint where the training actually went, relative to the loudest zone
     * of the same window. Two different questions, so the legend names the
     * window on screen rather than letting one colour mean both.
     *
     * @return array<string, string>
     */
    private function fills(string $zone): array
    {
        $fills = ['freshness' => $this->fill($zone)];
        foreach (MuscleFreshness::WINDOWS as $span) {
            $volume = (float) ($this->freshness[$zone]['windows'][$span]['volume'] ?? 0);
            $ceiling = (float) ($this->volumeCeiling[$span] ?? 0);
            $fills[(string) $span] = $ceiling > 0 ? $this->rampFill(100 * $volume / $ceiling) : 'var(--map-neutral)';
        }

        return $fills;
    }

    /**
     * One entry per labeled zone for the detail panel and the chip list.
     * Zones without history stay minimal on purpose: no freshness value
     * must ever be attached to them, "unknown" is not "recovered".
     */
    private function buildZones(): array
    {
        $zones = [];
        foreach ($this->zoneLabels as $zone => $label) {
            $data = $this->freshness[$zone] ?? null;
            $hasData = (bool) ($data['hasData'] ?? false);
            $entry = [
                'label' => $label,
                'hasData' => $hasData,
                'symptom' => $this->symptoms[$zone] ?? null,
            ];
            if ($hasData) {
                $freshness = (int) $data['freshness'];
                $daysSince = max(0, (int) Carbon::parse($data['lastTrained'])->startOfDay()->diffInDays(now()->startOfDay()));
                $band = $this->band($freshness);
                $entry += [
                    'freshness' => $freshness,
                    // The band is the primary reading, the percentage the
                    // secondary one: a decayed estimate calibrated against
                    // 90 days of one athlete's history cannot carry the
                    // precision a two-digit number implies.
                    'band' => $band,
                    'bandLabel' => $this->bandLabels()[$band],
                    // Second channel, never a second colour scale: the blue
                    // ramp stays the one carrier of "how much load", and a
                    // zone that needs a decision today gets an edge instead.
                    'flagged' => $freshness < self::SPENT_BELOW,
                    'lastTrained' => $data['lastTrained'],
                    'daysSince' => $daysSince,
                    'windows' => $data['windows'],
                    'corridor' => $this->corridor,
                    'recoversAt' => $data['recoversAt'] ?? null,
                    'fills' => $this->fills($zone),
                    'advice' => $this->advice($freshness, $daysSince),
                ];
            }
            $zones[$zone] = $entry;
        }

        return $zones;
    }

    /** @return array<string, string> */
    public function bandLabels(): array
    {
        return [
            'fresh' => __('fresh'),
            'loaded' => __('loaded'),
            'worked' => __('worked hard'),
            'spent' => __('barely recovered'),
        ];
    }

    /** The four readings the zone detail leads with, see advice(). */
    private function band(int $freshness): string
    {
        return match (true) {
            $freshness >= 75 => 'fresh',
            $freshness >= 45 => 'loaded',
            $freshness >= self::SPENT_BELOW => 'worked',
            default => 'spent',
        };
    }

    /**
     * Two lists, not one: zones with history ranked by rising freshness,
     * and the zones Garmin never reported. A ranking orders one quantity,
     * and "unknown" is not a smaller value of that quantity, so mixing the
     * two would invite reading the tail of the list as "least loaded".
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    private function splitZones(): array
    {
        $withData = [];
        $without = [];
        foreach ($this->zones as $zone => $entry) {
            if ($entry['hasData']) {
                $withData[$zone] = $entry['freshness'];
            } else {
                $without[] = $zone;
            }
        }
        asort($withData);

        return [array_keys($withData), $without];
    }

    /**
     * Coach guidance for today's session, derived from freshness and the
     * time since the last stimulus. The copy is deliberately directive:
     * the zone detail must end in a training decision, not in a number.
     */
    private function advice(int $freshness, int $daysSince): string
    {
        if ($freshness >= 75 && $daysSince >= 4) {
            return __('No stimulus for :days days and fully recovered: a good day to load this zone heavily again.', ['days' => $daysSince]);
        }
        if ($freshness >= 75) {
            return __('Well recovered. Heavy sets and high intensity are fair game here today.');
        }
        if ($freshness >= 45) {
            return __('Noticeable residual load. Normal training is fine, real maximum attempts are better left until tomorrow.');
        }
        if ($freshness >= self::SPENT_BELOW) {
            return __('Clearly pre-loaded. Technique or light volume at most today, the intensity belongs in fresher zones.');
        }

        return __('Barely recovered. Give this zone the day off or keep it to easy work, otherwise you are stacking fatigue on fatigue.');
    }

    public function render(): View
    {
        return view('components.body-map');
    }
}
