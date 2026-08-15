<?php

declare(strict_types=1);

namespace App\Garmin;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The conditions a night or a session happened in, and what the mirror's
 * own history says about them.
 *
 * Four numbers in this dashboard cannot be read honestly without the
 * weather. Deep sleep collapses on warm, humid nights, and the sleep card
 * alone calls that a bad night. Heart rate drifts upward in heat, so a
 * session at 30 C looks harder than the work was and the load model reads
 * it as fatigue. Sweat loss and the hydration goal are computed by Garmin
 * with a weather input the dashboard never saw. Daytime heat lifts resting
 * heart rate and baseline stress on its own.
 *
 * Everything here is cut from the hourly rows at the moment it is asked
 * for. Nothing is stored: the windows move (a night is whenever the
 * athlete slept), the hours do not.
 *
 * What this class will not do is claim a cause. It reports what
 * co-occurred, next to how many nights or sessions that was read over,
 * and it stays silent below a window where the split would be noise. The
 * mirror held nine days when this was asked for; two warm nights are not
 * a finding.
 */
class Weather
{
    /**
     * Nights and sessions needed before a warm/cool split is shown at all.
     *
     * Fourteen nights is two of every weekday, so the split cannot be a
     * single restless week; ten sessions is the smallest number where each
     * half still holds five. Both are floors, not thresholds of
     * significance, and the sample size travels with every claim so the
     * reader can discount it themselves.
     */
    public const MIN_NIGHTS = 14;

    public const MIN_SESSIONS = 10;

    /**
     * How far from the middle a night or a session has to sit before it is
     * called out as an outlier. The warmest and coolest fifth of the
     * athlete's own history, so the mark stays rare as the history grows
     * and means the same thing in January as in July.
     */
    private const OUTLIER_SHARE = 0.2;

    /** Below this many hours a window is too thin to average. */
    private const MIN_WINDOW_HOURS = 2;

    /** Days needed before the fluid split carries a number. */
    public const MIN_DAYS = 14;

    /**
     * The duration band a session has to sit in to take part.
     *
     * Circuit work has no distance, so there is no work term to hold the heart
     * rate against and duration is all the control there is. Comparing a
     * twenty minute session with a two hour one would report the session
     * length; inside three quarters of an hour to an hour and a half the
     * sessions are at least the same kind of thing.
     */
    private const SESSION_MIN_S = 2_700;

    private const SESSION_MAX_S = 5_400;

    /**
     * How many beats apart the two halves have to sit before the
     * difference is called one.
     *
     * A wrist sensor and a varying session leave a couple of beats of slack in
     * either direction, so anything under this is reported as "no
     * difference worth the name" rather than as a small effect. Saying
     * plainly that heat does not seem to touch these sessions is more use
     * than a decimal nobody should act on.
     */
    private const MIN_HR_EFFECT = 4.0;

    /**
     * The share of the athlete's own level a morning figure has to move by
     * before the split calls it a difference.
     *
     * Absolute beats will not do here the way they do for a session: a resting
     * pulse of 44 and one of 60 are different scales, and so are an HRV of
     * 30 ms and one of 90. Five per cent is under the day-to-day wobble
     * Garmin itself draws a baseline band around, so a split that clears
     * it is at least arguing above the noise.
     */
    private const MIN_RECOVERY_SHARE = 0.05;

    public function __construct(private GarminData $garmin) {}

    /**
     * The hourly rows, keyed by their local timestamp.
     *
     * Keyed rather than a list because every window below is a string
     * range over the same wall clock the mirror stores everywhere else,
     * so cutting one is a filter on the key.
     */
    public function hours(int $days = 120): Collection
    {
        return $this->garmin->weather($days)->keyBy('ts_local');
    }

    /** Whether this installation mirrors weather at all. */
    public function configured(): bool
    {
        return $this->garmin->weather(30)->isNotEmpty();
    }

    /**
     * Average the hours between two local timestamps.
     *
     * Inclusive at the front, exclusive at the back, so a session from
     * 18:00 to 19:00 counts the 18:00 hour once rather than twice.
     *
     * @return array{temperature: float, apparent: ?float, humidity: ?int, dewpoint: ?float, tempMin: float, tempMax: float, precipitation: float, uvMax: ?float, hours: int}|null
     */
    public static function window(Collection $hours, string $from, string $to, int $minHours = self::MIN_WINDOW_HOURS): ?array
    {
        $slice = $hours->filter(fn ($h) => $h->ts_local >= $from && $h->ts_local < $to);
        $temps = $slice->pluck('temperature_c')->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v);
        if ($temps->count() < $minHours) {
            return null;
        }

        $mean = function (string $column) use ($slice): ?float {
            $values = $slice->pluck($column)->filter(fn ($v) => $v !== null);

            return $values->isEmpty() ? null : round($values->avg(), 1);
        };

        return [
            'temperature' => round($temps->avg(), 1),
            'apparent' => $mean('apparent_c'),
            // The peak matters where the mean does not: a day averaging 22
            // that touches 29 in the afternoon is an afternoon to plan
            // around, and the mean would talk you out of it.
            'apparentMax' => ($ap = $slice->pluck('apparent_c')->filter(fn ($v) => $v !== null))->isEmpty()
                ? null
                : round((float) $ap->max(), 1),
            'humidity' => ($h = $mean('relative_humidity')) === null ? null : (int) round($h),
            'dewpoint' => $mean('dewpoint_c'),
            'tempMin' => round($temps->min(), 1),
            'tempMax' => round($temps->max(), 1),
            // Summed, not averaged: 4 mm in one hour and 4 mm spread over
            // four are the same wet session.
            'precipitation' => round($slice->pluck('precipitation_mm')->filter(fn ($v) => $v !== null)->sum(), 1),
            'uvMax' => ($uv = $slice->pluck('uv_index')->filter(fn ($v) => $v !== null))->isEmpty()
                ? null
                : round((float) $uv->max(), 1),
            'hours' => $temps->count(),
        ];
    }

    /**
     * The conditions a night was slept in.
     *
     * The window is the sleep itself, start to end, not the calendar
     * night: going to bed at 21:30 after a hot day is a different night
     * from going to bed at 01:00 once the air has cooled, and the
     * difference is exactly what this is meant to show.
     */
    public static function forNight(object $sleep, Collection $hours): ?array
    {
        if (empty($sleep->start_local) || empty($sleep->end_local)) {
            return null;
        }

        return self::window($hours, $sleep->start_local, $sleep->end_local);
    }

    /** The conditions a session was worked in, start over its duration. */
    public static function forSession(object $activity, Collection $hours): ?array
    {
        if (empty($activity->start_local) || ! $activity->duration_s) {
            return null;
        }
        $start = strtotime($activity->start_local);
        $end = date('Y-m-d H:i:s', $start + (int) $activity->duration_s);

        /* From the top of the hour the session started in, and one hour
           is enough. A 45 minute session sits inside a single reading, so the
           night's two-hour floor would drop every session there is, and an
           hourly figure for an hourly session is the true resolution of
           the source rather than a compromise. */
        return self::window($hours, date('Y-m-d H:00:00', $start), $end, 1);
    }

    /**
     * The daytime a body carried, 08:00 to 20:00.
     *
     * Not the calendar day: the overnight hours are the sleep window's
     * business, and folding them in would cool every summer day by the
     * night that followed it.
     */
    public static function forDay(string $date, Collection $hours): ?array
    {
        return self::window($hours, $date.' 08:00:00', $date.' 20:00:00');
    }

    /**
     * The hours of one date and the evening before it.
     *
     * Scanning all of them for every night would be a full pass over a
     * quarter's weather per window, and a night never reaches further
     * back than the previous evening anyway.
     */
    private static function around(Collection $byDate, string $date): Collection
    {
        $before = date('Y-m-d', strtotime($date.' -1 day'));

        return collect($byDate->get($before, []))->concat($byDate->get($date, []));
    }

    /**
     * Where a value sits in the athlete's own history: 'high', 'low' or
     * null for the ordinary middle.
     *
     * Percentiles of the material at hand rather than a fixed degree
     * count, because 19 C is a warm night in this mirror and an ordinary
     * one four hundred kilometres south.
     *
     * @param  array<int, float>  $sample
     */
    public static function outlier(float $value, array $sample, int $minSample): ?string
    {
        $n = count($sample);
        if ($n < $minSample) {
            return null;
        }
        sort($sample);
        $at = fn (float $share) => $sample[min($n - 1, max(0, (int) round($share * ($n - 1))))];

        return match (true) {
            $value >= $at(1 - self::OUTLIER_SHARE) => 'high',
            $value <= $at(self::OUTLIER_SHARE) => 'low',
            default => null,
        };
    }

    /**
     * Split pairs at the median of their condition and compare the two
     * halves.
     *
     * The blunt instrument on purpose. A correlation coefficient over
     * three dozen nights invites a confidence the data has not earned,
     * while "the warmer half of your nights and the cooler half, here is
     * each average and how many nights it was" is a statement the reader
     * can check against their own memory. The median split also survives
     * the one 4 C night in April that would drag a regression line
     * around by itself.
     *
     * Returns null below the minimum sample, which is the honest answer
     * for a mirror that is a week old.
     *
     * @param  array<int, array{0: float, 1: float}>  $pairs  [condition, outcome]
     * @return array{warm: float, cool: float, warmN: int, coolN: int, cut: float, difference: float}|null
     */
    public static function contrast(array $pairs, int $minSample): ?array
    {
        if (count($pairs) < $minSample) {
            return null;
        }
        $conditions = array_column($pairs, 0);
        sort($conditions);
        $n = count($conditions);
        $cut = $n % 2 === 0
            ? ($conditions[intdiv($n, 2) - 1] + $conditions[intdiv($n, 2)]) / 2
            : $conditions[intdiv($n, 2)];

        $warm = $cool = [];
        foreach ($pairs as [$condition, $outcome]) {
            if ($condition > $cut) {
                $warm[] = $outcome;
            } else {
                $cool[] = $outcome;
            }
        }
        // Every value equal to the median puts all of them on one side.
        // A comparison with an empty half is not a comparison.
        if (! $warm || ! $cool) {
            return null;
        }

        /* Median, not mean. The split itself is already a median cut, and
           the outcomes here have long tails: a 64 minute pilates session
           at a training load of 2 produced a per-load figure of 2,337
           where the median was 81, and three entries like it moved a
           group average further than any weather effect could. A median
           survives that without a magic threshold to argue about. */
        $mid = function (array $v) {
            sort($v);
            $n = count($v);

            return $n % 2 === 0 ? ($v[intdiv($n, 2) - 1] + $v[intdiv($n, 2)]) / 2 : $v[intdiv($n, 2)];
        };

        return [
            'warm' => round($mid($warm), 1),
            'cool' => round($mid($cool), 1),
            'warmN' => count($warm),
            'coolN' => count($cool),
            'cut' => round($cut, 1),
            'difference' => round($mid($warm) - $mid($cool), 1),
        ];
    }

    /**
     * Deep sleep on the athlete's warmer nights against their cooler ones.
     *
     * Dew point rather than temperature, because it is the number the
     * body actually meets: 22 C at 40 % humidity still lets sweat
     * evaporate, 22 C at 80 % does not, and above roughly 16 C dew point
     * the cooling route the body uses to enter deep sleep is closed.
     *
     * @return array{contrast: array, unit: string}|null
     */
    public function deepSleepByDewpoint(Collection $sleep, Collection $hours): ?array
    {
        $byDate = $hours->groupBy('date');
        $pairs = [];
        foreach ($sleep as $night) {
            if ($night->deep_s === null) {
                continue;
            }
            $w = self::forNight($night, self::around($byDate, $night->date));
            if ($w === null || $w['dewpoint'] === null) {
                continue;
            }
            $pairs[] = [$w['dewpoint'], $night->deep_s / 60];
        }

        $contrast = self::contrast($pairs, self::MIN_NIGHTS);

        return $contrast === null ? null : ['contrast' => $contrast, 'unit' => 'min'];
    }

    /**
     * What a warm day did to the morning after it.
     *
     * The point of this one is not the effect but the alibi. A resting
     * pulse three beats up and an HRV down on itself read as accumulated
     * fatigue, and the honest question is whether yesterday's heat can
     * account for it before training gets adjusted for something the
     * weather did. Read the morning after (day-of-strain offset),
     * because that is the night the day was slept off in.
     *
     * `material` is relative here rather than absolute: a resting pulse of
     * 44 and one of 60 do not move in the same units, and the athlete's
     * own level is the only sensible yardstick. Below it the answer is
     * that heat is not the explanation, which is worth more than a number.
     *
     * @return array{rhr: ?array, hrv: ?array}|null
     */
    public function recoveryByHeat(Collection $days, Collection $hrv, Collection $hours): ?array
    {
        $byDate = $hours->groupBy('date');
        $byDay = $days->keyBy('date');
        $byNight = $hrv->keyBy('date');
        $rhr = [];
        $variability = [];

        foreach ($byDay as $date => $day) {
            $w = self::forDay($date, self::around($byDate, $date));
            if ($w === null || $w['apparent'] === null) {
                continue;
            }
            $next = Carbon::parse($date)->addDay()->toDateString();
            if (($byDay[$next]->resting_hr ?? null) !== null) {
                $rhr[] = [$w['apparent'], (float) $byDay[$next]->resting_hr];
            }
            if (($byNight[$next]->last_night_avg ?? null) !== null) {
                $variability[] = [$w['apparent'], (float) $byNight[$next]->last_night_avg];
            }
        }

        $judged = function (array $pairs): ?array {
            $contrast = self::contrast($pairs, self::MIN_DAYS);
            if ($contrast === null) {
                return null;
            }

            return [
                'contrast' => $contrast,
                'material' => abs($contrast['difference']) >= abs($contrast['cool']) * self::MIN_RECOVERY_SHARE,
            ];
        };

        $split = ['rhr' => $judged($rhr), 'hrv' => $judged($variability)];

        return $split['rhr'] === null && $split['hrv'] === null ? null : $split;
    }

    /**
     * The days still ahead, measured against the ones behind.
     *
     * The forecast hours are in the same table as the archive ones, so
     * this is a cut by date rather than a second source. A degree figure
     * on its own tells an athlete nothing they could not read on a phone;
     * what the mirror adds is where that day sits in their own year, so
     * the same 27 °C reads differently in May than in August.
     *
     * @return array<int, array{offset: int, apparent: float, peak: ?float, outlier: ?string}>
     */
    public function outlook(Collection $hours, int $days = 2): array
    {
        $byDate = $hours->groupBy('date');
        $today = now()->toDateString();

        $history = [];
        foreach ($byDate->keys() as $date) {
            if ($date >= $today) {
                continue;
            }
            $w = self::forDay($date, self::around($byDate, $date));
            if ($w !== null && $w['apparent'] !== null) {
                $history[] = $w['apparent'];
            }
        }

        $ahead = [];
        foreach (range(1, $days) as $offset) {
            $date = now()->addDays($offset)->toDateString();
            $w = self::forDay($date, self::around($byDate, $date));
            if ($w === null || $w['apparent'] === null) {
                continue;
            }
            $ahead[] = [
                'offset' => $offset,
                'apparent' => $w['apparent'],
                'peak' => $w['apparentMax'],
                'outlier' => self::outlier($w['apparent'], $history, self::MIN_DAYS),
            ];
        }

        return $ahead;
    }

    /**
     * How much fluid a warm day asked for against a cool one.
     *
     * This is a quantity to act on, not a finding. Garmin computes both
     * the hydration goal and the sweat estimate with a weather input of
     * its own, so a warm/cool split over them retraces that formula
     * instead of discovering anything. What it adds is the number in the
     * athlete's own units: how much a day of the kind the forecast is
     * promising has cost before.
     *
     * One reader is left since the nutrition cut took the hydration card
     * with it: the outlook sentence, which says in the same breath that
     * it stands on a forecast rather than on a measurement.
     *
     * @return array{goal: ?array}|null
     */
    public function fluidByHeat(Collection $days, Collection $hours): ?array
    {
        $byDate = $hours->groupBy('date');
        $goal = [];
        foreach ($days as $day) {
            $w = self::forDay($day->date, self::around($byDate, $day->date));
            if ($w === null || $w['apparent'] === null) {
                continue;
            }
            if (($day->hydration_goal_ml ?? null) !== null) {
                $goal[] = [$w['apparent'], (float) $day->hydration_goal_ml];
            }
        }

        $split = ['goal' => self::contrast($goal, self::MIN_DAYS)];

        return $split['goal'] === null ? null : $split;
    }

    /**
     * What a circuit session's heart rate did in the heat, at a comparable length.
     *
     * The honest version of a question this dashboard got wrong twice.
     * Circuit work carries no distance, so there is nothing the heart rate can be
     * divided by that the heart rate did not help produce: training load
     * comes off the EPOC curve and calories come off the same
     * beats. What is left is the plain average, with duration held inside
     * a band so the comparison is not about session length, and the
     * knowledge that the session itself varies more than the weather does.
     *
     * Hence `material`: below a few beats the answer is that heat does
     * not seem to reach these sessions, which is worth saying outright
     * instead of dressing a rounding difference up as an effect. The gym
     * is usually indoors as well, so the temperature outside is a proxy
     * for the one in the room, and the card says that too.
     *
     * @return array{contrast: array, unit: string, material: bool}|null
     */
    public function sessionStrainByHeat(Collection $activities, Collection $hours): ?array
    {
        $byDate = $hours->groupBy('date');
        $pairs = [];
        foreach ($activities as $a) {
            if ($a->type_key !== 'hiit' || ! $a->avg_hr || ! $a->duration_s) {
                continue;
            }
            if ($a->duration_s < self::SESSION_MIN_S || $a->duration_s > self::SESSION_MAX_S) {
                continue;
            }
            $w = self::forSession($a, self::around($byDate, $a->date));
            if ($w === null || $w['apparent'] === null) {
                continue;
            }
            $pairs[] = [$w['apparent'], (float) $a->avg_hr];
        }

        $contrast = self::contrast($pairs, self::MIN_SESSIONS);
        if ($contrast === null) {
            return null;
        }

        return [
            'contrast' => $contrast,
            'unit' => 'bpm',
            'material' => abs($contrast['difference']) >= self::MIN_HR_EFFECT,
        ];
    }
}
