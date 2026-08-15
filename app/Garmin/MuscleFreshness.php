<?php

namespace App\Garmin;

use Illuminate\Support\Collection;

/**
 * Per-muscle-zone freshness (0 = just hammered, 100 = fully fresh).
 *
 * Every ACTIVE strength set contributes weight*reps load points to the
 * zones its Garmin exercise category maps to (config/muscle_map.php);
 * activities whose sets do not represent the workout (runs, circuit sessions with
 * sparse set data) contribute via their Garmin training load instead.
 * Points decay exponentially, per zone (config/muscle_map.php names the
 * half-lives and why they differ), and the "fully loaded" ceiling
 * self-calibrates against the hardest zone-day of the athlete's own
 * 90-day history. That one ceiling is shared by every zone, so absolute
 * units cancel out while the zones stay comparable with one another.
 *
 * Alongside the load the model carries three things that keep the map
 * honest about itself: how many fractional sets a zone actually saw, how
 * much of its load was measured rather than estimated from a whole
 * activity, and which sessions put it there.
 */
class MuscleFreshness
{
    /** Effective kg for body-weight/unknown-weight sets. */
    private const BODYWEIGHT_PROXY_KG = 45.0;

    /** Load points per unit of Garmin training load for set-less activities. */
    private const ACTIVITY_LOAD_SCALE = 60.0;

    /**
     * Sets replace the whole-activity fallback only when they look like a
     * fully tracked strength session: a real session rarely has fewer than
     * 5 working sets, while Garmin logs circuit sessions with 1-4 mostly
     * UNKNOWN sets that cover a fraction of the workout.
     */
    private const MIN_MAPPED_SETS = 5;

    /** Freshness a zone counts as recovered at, for the forecast. */
    private const RECOVERED_AT = 90;

    /** Beyond this the forecast is a guess about next week, not a plan. */
    private const FORECAST_MAX_HOURS = 168;

    /**
     * The windows the volume lens offers, in days. Seven is the week the
     * set corridor is defined over; 28 is long enough for a neglected zone
     * to stand out against a training block.
     */
    public const WINDOWS = [7, 28];

    /**
     * Zone keys as Garmin spells them, with the words the surfaces show.
     * The one source for body map, teaser and deficit hints: the map is a
     * view component and the hints are a Garmin model, so the vocabulary
     * lives here on the domain side where both may reach it.
     *
     * @return array<string, string>
     */
    public static function zoneLabels(): array
    {
        return [
            'CHEST' => __('Chest'), 'FRONT_DELTOIDS' => __('Front delts'),
            'BACK_DELTOIDS' => __('Rear delts'), 'BICEPS' => __('Biceps'),
            'TRICEPS' => __('Triceps'), 'FOREARM' => __('Forearms'), 'ABS' => __('Abs'),
            'OBLIQUES' => __('Obliques'), 'QUADRICEPS' => __('Quads'),
            'HAMSTRING' => __('Hamstrings'), 'GLUTEAL' => __('Glutes'), 'CALVES' => __('Calves'),
            'TRAPEZIUS' => __('Traps'), 'UPPER_BACK' => __('Upper back'),
            'LOWER_BACK' => __('Lower back'), 'ABDUCTORS' => __('Abductors'),
        ];
    }

    /** Half-life in hours for one zone, falling back to the global one. */
    public static function halfLife(string $zone): float
    {
        $config = config('muscle_map');

        return (float) ($config['zone_half_life_hours'][$zone] ?? $config['half_life_hours']);
    }

    public function compute(Collection $sets, Collection $activities): array
    {
        $config = config('muscle_map');
        $zones = $config['zones'];

        $dayZone = $this->dayZoneLoad($sets, $activities);
        if ($dayZone === []) {
            return ['zones' => [], 'hasData' => false];
        }

        // Decayed accumulation for a given moment, per zone. The half-life
        // is the zone's own: a quadriceps carries its session into the
        // third day, a triceps does not, and one shared constant used to
        // claim otherwise.
        $accAt = function (string $zone, int $atTs) use ($dayZone): float {
            $halfLifeH = self::halfLife($zone);
            $acc = 0.0;
            foreach ($dayZone[$zone] ?? [] as $day => $entry) {
                $eventTs = strtotime($day.' 18:00'); // typical session time
                $hours = ($atTs - $eventTs) / 3600.0;
                if ($hours < -12 || $hours > 24 * 12) {
                    continue;
                }
                $acc += $entry['points'] * pow(0.5, max(0.0, $hours) / $halfLifeH);
            }

            return $acc;
        };

        // Calibration ceiling: the hardest zone-day of the window, shared
        // by every zone. One scale is what makes the zones comparable at
        // all; a body map is a set of small multiples, and per-zone ceilings
        // would turn the colour from "how much load is left here" into "how
        // unusual is this for this one zone". Measured on the current
        // mirror, per-zone ceilings put the upper back at 65 % next to the
        // quadriceps at 73 %, although the back carries barely half the
        // load; the shared ceiling separates them to 36 % and 73 %. It also
        // removes a trap: a rarely trained zone has a tiny ceiling of its
        // own, so its first session would paint it darkest on the map.
        //
        // The maximum, not a percentile: most zone-days are rest decay, so
        // p95 sits far below a real session peak. On this mirror a shared
        // p95 pinned four zones at 100 % at once and left the middle of the
        // ramp unused.
        $now = time();
        $ceiling = 0.0;
        foreach ($zones as $zone) {
            for ($i = (int) $config['calibration_days']; $i >= 0; $i--) {
                $ceiling = max($ceiling, $accAt($zone, strtotime("-{$i} days 21:00")));
            }
        }

        $result = [];
        foreach ($zones as $zone) {
            $accNow = $accAt($zone, $now);
            $freshness = $ceiling > 0 ? 100.0 * (1.0 - min(1.0, $accNow / $ceiling)) : 100.0;

            $days = $dayZone[$zone] ?? [];
            krsort($days);

            $result[$zone] = [
                // A zone Garmin never reported load for looks identical to a
                // fully recovered one (freshness 100). Keep them apart so the
                // map can say "no data" instead of claiming "fresh".
                'hasData' => $days !== [],
                'freshness' => (int) round($freshness),
                'lastTrained' => array_key_first($days),
                'windows' => $this->windows($days),
                'recoversAt' => $this->recoveryForecast($accNow, $ceiling, $zone, $days),
            ];
        }

        return ['zones' => $result, 'hasData' => true, 'volumeCeiling' => $this->volumeCeilings($result)];
    }

    /**
     * One summary per lens window: what the zone actually saw in the last
     * 7 and 28 days. Unlike freshness these numbers carry no decay, so
     * they answer "where did my training go" rather than "what is left in
     * the tank", which is why the lens has to name its window on screen.
     *
     * @param  array<string, array{points: float, sets: float, measured: float, sessions: array<string, array{label: string, points: float}>}>  $days
     * @return array<int, array{volume: float, sets: float, measuredShare: int|null, days: int, sessions: list<array{label: string, date: string, share: int}>}>
     */
    private function windows(array $days): array
    {
        $windows = [];
        foreach (self::WINDOWS as $span) {
            $since = now()->subDays($span)->toDateString();
            $volume = 0.0;
            $sets = 0.0;
            $measured = 0.0;
            $loadDays = 0;
            $sessions = [];
            foreach ($days as $day => $entry) {
                if ($day < $since) {
                    continue;
                }
                $volume += $entry['points'];
                $sets += $entry['sets'];
                $measured += $entry['measured'];
                $loadDays++;
                foreach ($entry['sessions'] as $key => $session) {
                    $sessions[$key] = [
                        'label' => $session['label'],
                        'date' => $day,
                        'points' => ($sessions[$key]['points'] ?? 0.0) + $session['points'],
                    ];
                }
            }

            $windows[$span] = [
                'volume' => round($volume),
                // Fractional sets, the unit the dose-response literature
                // counts in: a set counts for a zone as much as the exercise
                // loads it. Only real strength sets reach this number, so a
                // circuit-heavy block reads low here on purpose.
                'sets' => round($sets, 1),
                // How much of the load was measured from sets rather than
                // spread from a whole activity's training load. Without it
                // an estimated zone looks exactly like a measured one.
                'measuredShare' => $volume > 0 ? (int) round(100 * $measured / $volume) : null,
                'days' => $loadDays,
                'sessions' => $this->rankSessions($sessions, $volume),
            ];
        }

        return $windows;
    }

    /**
     * The loudest zone per window, which is what the volume lens paints
     * its darkest step against. Deliberately relative: unlike freshness
     * there is no "fully loaded" anchor for raw volume, so the legend says
     * "compared with your loudest zone" instead of implying an absolute.
     *
     * @param  array<string, array{windows: array<int, array{volume: float}>}>  $zones
     * @return array<int, float>
     */
    private function volumeCeilings(array $zones): array
    {
        $ceilings = [];
        foreach (self::WINDOWS as $span) {
            $ceilings[$span] = max(0.0, ...array_map(
                fn (array $zone) => (float) $zone['windows'][$span]['volume'],
                array_values($zones),
            ));
        }

        return $ceilings;
    }

    /**
     * When the zone is projected back at RECOVERED_AT % freshness. Past
     * the last event every term of the sum shares one half-life, so the
     * whole accumulation decays as a single exponential and the moment
     * inverts in closed form rather than by stepping through hours.
     *
     * Null means either nothing to wait for or nothing worth saying: an
     * already recovered zone, and one whose projection runs past a week.
     *
     * @param  array<string, array{points: float}>  $days
     */
    private function recoveryForecast(float $accNow, float $ceiling, string $zone, array $days): ?string
    {
        $target = $ceiling * (1.0 - self::RECOVERED_AT / 100);
        if ($ceiling <= 0.0 || $accNow <= $target || $target <= 0.0) {
            return null;
        }

        // Start the projection after the last event, not at this moment:
        // sessions are booked at 18:00, so before the evening a same-day
        // event still lies ahead and the closed form would not hold yet.
        $lastEventTs = 0;
        foreach (array_keys($days) as $day) {
            $lastEventTs = max($lastEventTs, (int) strtotime($day.' 18:00'));
        }
        $from = max(time(), $lastEventTs);

        $hours = self::halfLife($zone) * log($accNow / $target, 2);
        $at = $from + (int) round($hours * 3600);
        if ($at - time() > self::FORECAST_MAX_HOURS * 3600) {
            return null;
        }

        return date('Y-m-d H:i', $at);
    }

    /**
     * The sessions behind a zone's week, strongest first, as whole-percent
     * shares of its load. Attribution is what lets the athlete catch a
     * mapping error: a zone that claims load from a session that never
     * touched it is visible here and nowhere else.
     *
     * @param  array<string, array{label: string, date: string, points: float}>  $sessions
     * @return list<array{label: string, date: string, share: int}>
     */
    private function rankSessions(array $sessions, float $weekVolume): array
    {
        if ($sessions === [] || $weekVolume <= 0) {
            return [];
        }

        uasort($sessions, fn (array $a, array $b) => $b['points'] <=> $a['points']);

        $ranked = [];
        foreach (array_slice($sessions, 0, 3) as $session) {
            $share = (int) round(100 * $session['points'] / $weekVolume);
            if ($share < 1) {
                continue;
            }
            $ranked[] = ['label' => $session['label'], 'date' => $session['date'], 'share' => $share];
        }

        return $ranked;
    }

    /**
     * Load per zone per day, the event base compute() builds on. Daily
     * buckets keep calibration cheap; alongside the points each bucket
     * carries what the points are made of, so the surfaces can report
     * their own reliability instead of implying a precision the data
     * does not have.
     *
     * @return array<string, array<string, array{points: float, sets: float, measured: float, sessions: array<string, array{label: string, points: float}>}>>
     */
    private function dayZoneLoad(Collection $sets, Collection $activities): array
    {
        $config = config('muscle_map');

        // 0. Decide per activity whether its logged sets represent the
        // workout: at least MIN_MAPPED_SETS mapped sets forming the majority
        // of its active sets. Otherwise the activity falls back to its
        // training load so sparse circuit set data cannot suppress it.
        $setStats = [];
        foreach ($sets as $set) {
            $id = $set->activity_id;
            $setStats[$id]['total'] = ($setStats[$id]['total'] ?? 0) + 1;
            // Same lookup order as the event pass below: a set the exercise
            // name rescues is mapped, and counting it as unmapped here
            // would keep its own session on the fallback.
            if (! empty($config['exercises'][trim((string) ($set->exercise_name ?? ''))])
                || ! empty($config['categories'][$set->exercise_category])) {
                $setStats[$id]['mapped'] = ($setStats[$id]['mapped'] ?? 0) + 1;
            }
        }
        $setsRepresentative = [];
        foreach ($setStats as $id => $s) {
            $mapped = $s['mapped'] ?? 0;
            $setsRepresentative[$id] = $mapped >= self::MIN_MAPPED_SETS && $mapped >= 0.5 * $s['total'];
        }

        $names = [];
        foreach ($activities as $a) {
            $names[$a->id] = $this->sessionLabel($a);
        }

        // 1. Collect load events: [timestamp, zone => weight, points, ...]
        $events = [];

        foreach ($sets as $set) {
            if (empty($setsRepresentative[$set->activity_id])) {
                continue; // activity is accounted via training load instead
            }
            // The specific variant wins over the category when we know it
            // maps somewhere else; see config/muscle_map.php.
            $mapping = $config['exercises'][trim((string) ($set->exercise_name ?? ''))]
                ?? $config['categories'][$set->exercise_category]
                ?? null;
            if (! $mapping) {
                continue;
            }
            $kg = $set->weight_g ? $set->weight_g / 1000.0 : self::BODYWEIGHT_PROXY_KG;
            $reps = $set->reps ?: max(1, (int) round(($set->duration_s ?? 30) / 4));
            $points = $kg * $reps;
            $ts = strtotime($set->start_local ?? $set->activity_start ?? $set->activity_date.' 12:00');
            $events[] = [
                'ts' => $ts,
                'mapping' => $mapping,
                'points' => $points,
                'measured' => true,
                'session' => (string) $set->activity_id,
                'label' => $names[$set->activity_id] ?? __('Strength'),
            ];
        }

        foreach ($activities as $a) {
            if (! empty($setsRepresentative[$a->id])) {
                continue; // sets already cover this activity
            }
            $mapping = $config['activity_types'][$a->type_key] ?? null;
            if (! $mapping || ! $a->training_load) {
                continue;
            }
            $ts = strtotime($a->start_local ?? $a->date.' 12:00');
            $events[] = [
                'ts' => $ts,
                'mapping' => $mapping,
                'points' => (float) $a->training_load * self::ACTIVITY_LOAD_SCALE,
                'measured' => false,
                'session' => (string) $a->id,
                'label' => $names[$a->id] ?? __('Session'),
            ];
        }

        // 2. Aggregate per zone per day.
        $dayZone = [];
        foreach ($events as $event) {
            $day = date('Y-m-d', $event['ts']);
            foreach ($event['mapping'] as $zone => $weight) {
                $points = $event['points'] * $weight;
                $session = $event['session'];
                $dayZone[$zone][$day]['points'] = ($dayZone[$zone][$day]['points'] ?? 0.0) + $points;
                // A set counts as much of a set for this zone as the
                // exercise loads it, which is the fractional counting the
                // dose-response meta-regression found most predictive.
                // Whole-activity fallbacks never carry sets: a circuit
                // session's training load is a stimulus, not a countable set.
                $dayZone[$zone][$day]['sets']
                    = ($dayZone[$zone][$day]['sets'] ?? 0.0) + ($event['measured'] ? $weight : 0.0);
                $dayZone[$zone][$day]['measured']
                    = ($dayZone[$zone][$day]['measured'] ?? 0.0) + ($event['measured'] ? $points : 0.0);
                $dayZone[$zone][$day]['sessions'][$session]['label'] = $event['label'];
                $dayZone[$zone][$day]['sessions'][$session]['points']
                    = ($dayZone[$zone][$day]['sessions'][$session]['points'] ?? 0.0) + $points;
            }
        }

        return $dayZone;
    }

    /**
     * What to call a session in the attribution list. The activity type
     * carries the meaning; Garmin's auto-generated name does not, because
     * it is the place plus the sport in the device language ("Springfield
     * Running") and three rows reading "HIIT" tell the athlete nothing
     * about which session did what. What the athlete typed themselves
     * sits behind a separator ("Strength - 50 WB unbroken"), and that
     * part is worth keeping.
     */
    private function sessionLabel(object $activity): string
    {
        $type = match ($activity->type_key) {
            'hiit' => __('HIIT'),
            'strength_training' => __('Strength'),
            'running', 'treadmill_running', 'trail_running' => __('Run'),
            'cycling' => __('Ride'),
            'indoor_rowing' => __('Row'),
            'pilates' => __('Pilates'),
            'indoor_cardio', 'fitness_equipment' => __('Cardio'),
            default => __('Session'),
        };

        $name = trim((string) ($activity->name ?? ''));
        if (preg_match('/[-:–—]\s*(\S.*)$/u', $name, $match)) {
            return $type.': '.trim($match[1]);
        }

        return $type;
    }
}
