<?php

declare(strict_types=1);

namespace App\Garmin;

use Illuminate\Support\Collection;

/**
 * Weekly strength progression from the per-set recordings the mirror
 * already holds: volume, top weights, bests and a stagnation reading
 * per exercise category.
 *
 * The shape follows the data, not the wish. In this mirror the watch
 * counts reps for almost every active set but carries a weight for
 * almost none (circuit work is tracked off the wrist, nobody types plates into
 * a watch mid-workout), and most sets land in Garmin's UNKNOWN
 * category. So reps are the one volume measure every category can
 * honestly report, and tonnage exists only where a set really carries a
 * weight. The two are never mixed: a category's kilogram volume sums
 * only its weighted sets, and no rep is ever converted into an invented
 * kilogram.
 */
class StrengthProgression
{
    /**
     * A category is read as weight-trained only when the majority of its
     * active sets carry a weight. Below that the odd logged dumbbell says
     * "someone typed one in once", not "this is tracked tonnage", and a
     * kilogram series built from it would be mostly holes posing as work.
     */
    private const WEIGHTED_MAJORITY = 0.5;

    /** Complete weeks the regularity gate looks back over. */
    private const REGULAR_WINDOW = 4;

    /** Weight-trained weeks required inside that window before "regularly trained" holds. */
    private const REGULAR_MIN = 3;

    /** Trailing weeks the top weight must hold before stagnation is worth a sentence. */
    private const STAGNANT_MIN_WEEKS = 3;

    /**
     * Two recorded weights this close count as the same weight. Weights
     * arrive as float grams; the tolerance absorbs float noise without
     * ever bridging a real 2.5 kg plate step.
     */
    private const SAME_KG = 0.05;

    /**
     * The weekly model over the last $lastWeeks ISO weeks, from set rows
     * as GarminData::strengthSets() hands them over (REST already
     * filtered, activity_date joined on).
     *
     * Totals, bests and the stagnation reading are computed inside the
     * sliced window only, so they answer for the range the reader sees,
     * like the KPI tiles of the neighbouring cards do.
     *
     * @return array{
     *     weeks: array<int, string>,
     *     runningIndex: ?int,
     *     sessions: int,
     *     anyWeight: bool,
     *     categories: array<int, array<string, mixed>>,
     * }
     */
    public function weekly(Collection $sets, int $lastWeeks): array
    {
        $byWeek = [];
        $sessionsByWeek = [];
        foreach ($sets as $set) {
            // Defensive rather than trusted: the accessor filters REST,
            // but this model must never count pauses as training even
            // when a test or a future caller feeds it raw rows.
            if (($set->set_type ?? null) === 'REST' || ! ($set->activity_date ?? null)) {
                continue;
            }
            // Garmin's own word for "could not classify" is UNKNOWN; a
            // row without any category means the same thing and is
            // folded in rather than becoming a second nameless bucket.
            $category = ($set->exercise_category ?? '') !== '' ? $set->exercise_category : 'UNKNOWN';
            $week = date('o-\WW', strtotime($set->activity_date));
            $reps = $set->reps === null ? null : (int) $set->reps;
            $kg = ($set->weight_g ?? 0) > 0 ? $set->weight_g / 1000 : null;

            $cell = &$byWeek[$week][$category];
            $cell['sets'] = ($cell['sets'] ?? 0) + 1;
            if ($reps !== null) {
                $cell['reps'] = ($cell['reps'] ?? 0) + $reps;
            }
            if ($kg !== null) {
                $cell['weightedSets'] = ($cell['weightedSets'] ?? 0) + 1;
                $cell['topKg'] = max($cell['topKg'] ?? 0, $kg);
                if ($reps !== null) {
                    $cell['kg'] = ($cell['kg'] ?? 0) + $kg * $reps;
                }
            }
            unset($cell);

            $sessionsByWeek[$week][$set->activity_id] = true;
        }

        if ($byWeek === []) {
            return ['weeks' => [], 'runningIndex' => null, 'sessions' => 0, 'anyWeight' => false, 'categories' => []];
        }
        ksort($byWeek);

        // The running week always occupies the last slot, even before its
        // first set: without it the newest bar would silently be last
        // week's, exactly the trap the neighbouring load card guards
        // against. Weeks between sessions are filled, a gap is a zero.
        // now() rather than date(): the native call reads the system clock
        // and ignores a frozen test time, so a test pinned to a Friday kept
        // agreeing with it until the real week rolled over and then failed
        // on a calendar boundary rather than on anything in the code.
        $thisWeek = now()->format('o-\WW');
        $weeks = self::weekGrid((string) array_key_first($byWeek), max((string) array_key_last($byWeek), $thisWeek));
        $weeks = array_slice($weeks, -$lastWeeks);
        $runningIndex = ($i = array_search($thisWeek, $weeks, true)) === false ? null : $i;

        $categories = [];
        foreach ($weeks as $w => $week) {
            foreach ($byWeek[$week] ?? [] as $key => $cell) {
                $c = &$categories[$key];
                $c ??= [
                    'key' => $key,
                    'sets' => 0,
                    'weightedSets' => 0,
                    'reps' => array_fill(0, count($weeks), 0),
                    'kgWeeks' => array_fill(0, count($weeks), 0.0),
                    'topKg' => array_fill(0, count($weeks), null),
                ];
                $c['sets'] += $cell['sets'];
                $c['weightedSets'] += $cell['weightedSets'] ?? 0;
                $c['reps'][$w] = $cell['reps'] ?? 0;
                $c['kgWeeks'][$w] = round($cell['kg'] ?? 0, 1);
                $c['topKg'][$w] = isset($cell['topKg']) ? round($cell['topKg'], 1) : null;
                unset($c);
            }
        }

        // Largest first, because the surface names only the biggest
        // categories and folds the rest. Reps break ties, the key makes
        // the order stable when even those agree.
        uasort($categories, fn (array $a, array $b) => [$b['sets'], array_sum($b['reps']), $a['key']]
            <=> [$a['sets'], array_sum($a['reps']), $b['key']]);

        $anyWeight = false;
        $out = [];
        foreach ($categories as $c) {
            $weighted = $c['weightedSets'] > $c['sets'] * self::WEIGHTED_MAJORITY;
            $tops = array_filter($c['topKg'], fn (?float $t) => $t !== null);
            $anyWeight = $anyWeight || $tops !== [];
            $lastComplete = $runningIndex === null ? count($weeks) - 1 : $runningIndex - 1;

            $out[] = [
                'key' => $c['key'],
                'sets' => $c['sets'],
                'weighted' => $weighted,
                'reps' => $c['reps'],
                // Tonnage only where it is real: a mostly weightless
                // category reports no kilogram series at all instead of
                // a curve made from its two logged dumbbells.
                'kg' => $weighted ? $c['kgWeeks'] : null,
                'topKg' => $c['topKg'],
                'currentTopKg' => $tops === [] ? null : end($tops),
                'bestSetKg' => $tops === [] ? null : max($tops),
                'bestWeekKg' => $weighted ? max($c['kgWeeks']) : null,
                'bestWeekReps' => max($c['reps']),
                'lastFullWeekReps' => $lastComplete >= 0 ? $c['reps'][$lastComplete] : null,
                'stagnation' => $this->stagnation($c['topKg'], $weighted, $runningIndex),
            ];
        }

        return [
            'weeks' => $weeks,
            'runningIndex' => $runningIndex,
            'sessions' => count(array_unique(array_merge(
                ...array_map(fn (string $week) => array_keys($sessionsByWeek[$week] ?? []), $weeks)
            ))),
            'anyWeight' => $anyWeight,
            'categories' => $out,
        ];
    }

    /**
     * "Holds at X kg for N weeks", or nothing.
     *
     * An observation about a habit, never a verdict, so it only speaks
     * where a habit exists: the category must have been weight-trained
     * in most of the recent complete weeks, and its weekly top must have
     * sat on the same value the whole trailing stretch. Everything else
     * stays silent on purpose. A pause is a gap, not stagnation; a top
     * that moved, in either direction, is change; and a heavier set in
     * the running week has already ended the claim, so the sentence must
     * not outlive it by a page load.
     *
     * @param  array<int, ?float>  $topKg
     * @return array{weeks: int, kg: float}|null
     */
    private function stagnation(array $topKg, bool $weighted, ?int $runningIndex): ?array
    {
        if (! $weighted) {
            return null;
        }
        $complete = $runningIndex === null ? $topKg : array_slice($topKg, 0, $runningIndex);
        $n = count($complete);
        if ($n < self::STAGNANT_MIN_WEEKS) {
            return null;
        }

        $recent = array_slice($complete, -self::REGULAR_WINDOW);
        if (count(array_filter($recent, fn (?float $t) => $t !== null)) < self::REGULAR_MIN) {
            return null;
        }

        // Walk back from the newest complete week: the run may bridge a
        // single skipped week (the regularity gate tolerates one too),
        // two in a row break it, a different top ends it.
        $last = null;
        $runStart = null;
        $gap = 0;
        for ($i = $n - 1; $i >= 0; $i--) {
            if ($complete[$i] === null) {
                if ($last !== null && ++$gap > 1) {
                    break;
                }

                continue;
            }
            if ($last !== null && abs($complete[$i] - $last) >= self::SAME_KG) {
                break;
            }
            $gap = 0;
            $last ??= $complete[$i];
            $runStart = $i;
        }

        if ($last === null) {
            return null;
        }
        $trained = count(array_filter(array_slice($complete, $runStart), fn (?float $t) => $t !== null));
        if ($trained < self::STAGNANT_MIN_WEEKS) {
            return null;
        }

        // A running week already lifting a different top has ended the
        // stretch; saying "holds" beside it would be yesterday's claim.
        $runningTop = $runningIndex !== null ? $topKg[$runningIndex] : null;
        if ($runningTop !== null && abs($runningTop - $last) >= self::SAME_KG) {
            return null;
        }

        return ['weeks' => $n - $runStart, 'kg' => $last];
    }

    /**
     * The mirror's category vocabulary as words: Garmin's enum keys are
     * data, not source strings, so they are humanised mechanically
     * rather than fed through the translation catalogue one by one.
     * Only UNKNOWN gets a real word, because "Unknown" on a card reads
     * as an error where it actually means "the watch could not classify
     * this movement".
     */
    public static function label(string $category): string
    {
        if ($category === 'UNKNOWN') {
            return __('Unclassified');
        }

        return ucfirst(strtolower(str_replace('_', ' ', $category)));
    }

    /**
     * Every ISO week from $from to $to inclusive. Same semantics as the
     * chart bundle's grid; duplicated here rather than imported, because
     * a derived-metric class must not depend on the view layer.
     *
     * @return array<int, string>
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
}
