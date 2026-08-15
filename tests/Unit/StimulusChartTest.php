<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\View\Dashboard\ChartBundle;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The two cards that split training by the kind of stimulus it was: the
 * weekly load stack and the aerobic-against-anaerobic scatter. Both read the
 * same bucket rule, so the cases below also pin down that they cannot drift
 * apart about what counts as a run.
 */
class StimulusChartTest extends TestCase
{
    /** @param  array<int, mixed>  $args */
    private function invoke(string $method, array $args): mixed
    {
        $reflected = new ReflectionMethod(ChartBundle::class, $method);
        $reflected->setAccessible(true);

        return $reflected->invokeArgs(app(ChartBundle::class), $args);
    }

    /** One activity row in the shape the mirror hands over. */
    private function activity(string $date, string $type, ?float $load = null, ?float $aerobic = null, ?float $anaerobic = null): object
    {
        return (object) [
            'date' => $date,
            'type_key' => $type,
            'training_load' => $load,
            'aerobic_te' => $aerobic,
            'anaerobic_te' => $anaerobic,
        ];
    }

    /** A date this many whole weeks back, so the week grid stays deterministic. */
    private function weeksAgo(int $weeks): string
    {
        return date('Y-m-d', strtotime('-'.(7 * $weeks).' days'));
    }

    public function test_weekly_load_splits_into_four_kinds_with_combo_on_its_own(): void
    {
        $st = $this->invoke('stimulusLoad', [collect([
            $this->activity($this->weeksAgo(2), 'running', 100),
            $this->activity($this->weeksAgo(2), 'hiit', 300),
            $this->activity($this->weeksAgo(1), 'walking', 50),
            $this->activity($this->weeksAgo(0), 'multi_sport', 200),
            $this->activity($this->weeksAgo(0), 'strength_training', 200),
        ]), 3]);

        $this->assertSame([100, 0, 0], $st['run']);
        $this->assertSame([300, 0, 200], $st['strength']);
        $this->assertSame([0, 0, 200], $st['combo']);
        $this->assertSame([0, 50, 0], $st['other']);
        $this->assertSame([400, 50, 400], $st['total']);
        // The running week is marked so the chart can label it as unfinished.
        $this->assertSame(2, $st['runningIndex']);
    }

    public function test_running_share_counts_combo_sessions_as_half_running(): void
    {
        $st = $this->invoke('stimulusLoad', [collect([
            // A pure run and a circuit session: a quarter of the load ran.
            $this->activity($this->weeksAgo(2), 'running', 100),
            $this->activity($this->weeksAgo(2), 'hiit', 300),
            // Trained, none of it running: zero, which is a fact, not a gap.
            $this->activity($this->weeksAgo(1), 'strength_training', 200),
            // Combo counts in: half the load is race-specific running.
            $this->activity($this->weeksAgo(0), 'multi_sport', 200),
            $this->activity($this->weeksAgo(0), 'strength_training', 200),
        ]), 3]);

        $this->assertSame([25, 0, 50], $st['runShare']);
    }

    public function test_a_week_without_a_session_is_a_zero_bar_and_a_share_of_null(): void
    {
        $st = $this->invoke('stimulusLoad', [collect([
            $this->activity($this->weeksAgo(2), 'running', 120),
        ]), 3]);

        $this->assertCount(3, $st['weeks']);
        $this->assertSame([120, 0, 0], $st['run']);
        $this->assertSame([120, 0, 0], $st['total']);
        /* Null rather than zero on the two empty weeks: "trained nothing"
           and "trained, none of it running" are different weeks, and only
           one of them is a problem worth drawing. */
        $this->assertSame([100, null, null], $st['runShare']);
    }

    public function test_activities_without_a_load_never_reach_the_stack(): void
    {
        $st = $this->invoke('stimulusLoad', [collect([
            $this->activity($this->weeksAgo(1), 'running', null),
            $this->activity($this->weeksAgo(1), 'running', 0.0),
            $this->activity($this->weeksAgo(1), 'pilates', 40),
        ]), 2]);

        $this->assertSame([0, 0], $st['run']);
        $this->assertSame([40, 0], $st['other']);
    }

    public function test_a_zero_training_effect_is_a_reading_and_stays_on_the_plot(): void
    {
        $te = $this->invoke('trainingEffectPoints', [collect([
            // A walk that asked nothing of either system. Measured, not missing.
            $this->activity('2026-07-10', 'walking', 5, 0.0, 0.0),
            // Genuinely absent values, the only kind that may be dropped.
            $this->activity('2026-07-11', 'running', 90, null, 1.2),
            $this->activity('2026-07-12', 'running', 90, 3.4, null),
        ]), '2026-07-01']);

        $this->assertSame(1, $te['count']);
        $this->assertSame(0.0, $te['groups'][0]['points'][0]['x']);
        $this->assertSame(0.0, $te['groups'][0]['points'][0]['y']);
    }

    public function test_sessions_before_the_window_are_left_out(): void
    {
        $te = $this->invoke('trainingEffectPoints', [collect([
            $this->activity('2026-06-30', 'running', 90, 3.0, 0.5),
            $this->activity('2026-07-01', 'running', 90, 3.0, 0.5),
        ]), '2026-07-01']);

        $this->assertSame(1, $te['count']);
    }

    public function test_sessions_on_one_coordinate_become_one_point_carrying_the_count(): void
    {
        $te = $this->invoke('trainingEffectPoints', [collect([
            $this->activity('2026-07-10', 'walking', 5, 0.2, 0.0),
            $this->activity('2026-07-11', 'walking', 5, 0.2, 0.0),
            $this->activity('2026-07-12', 'walking', 5, 0.3, 0.0),
        ]), '2026-07-01']);

        // Keyed as strings: a float array key would silently truncate to 0
        // and fold the two coordinates back together.
        $points = collect($te['groups'][0]['points'])->keyBy(fn (array $p) => (string) $p['x']);

        $this->assertCount(2, $points);
        $this->assertSame(2, $points['0.2']['n']);
        $this->assertSame(1, $points['0.3']['n']);
        // The tooltip names the most recent of the collapsed sessions.
        $this->assertSame('2026-07-11', $points['0.2']['date']);
        // The count of sessions, not of the markers they collapsed into.
        $this->assertSame(3, $te['count']);
    }

    public function test_the_densest_kind_is_drawn_first_so_the_rare_ones_stay_visible(): void
    {
        $te = $this->invoke('trainingEffectPoints', [collect([
            $this->activity('2026-07-10', 'running', 90, 3.0, 0.5),
            $this->activity('2026-07-11', 'hiit', 90, 2.0, 2.5),
            $this->activity('2026-07-12', 'hiit', 90, 2.1, 2.6),
            $this->activity('2026-07-13', 'hiit', 90, 2.2, 2.7),
        ]), '2026-07-01']);

        $this->assertSame(['strength', 'run'], array_column($te['groups'], 'bucket'));
        $this->assertSame('Strength & HIIT', $te['groups'][0]['label']);
    }

    public function test_both_cards_agree_on_what_counts_as_which_kind(): void
    {
        $activities = collect([
            $this->activity('2026-07-10', 'running', 100, 3.0, 0.5),
            $this->activity('2026-07-10', 'hiit', 100, 2.0, 2.5),
            $this->activity('2026-07-10', 'multi_sport', 100, 4.0, 1.5),
            $this->activity('2026-07-10', 'pilates', 100, 1.0, 0.0),
        ]);

        $te = $this->invoke('trainingEffectPoints', [$activities, '2026-07-01']);
        $st = $this->invoke('stimulusLoad', [$activities, 60]);

        $week = array_search(date('o-\WW', strtotime('2026-07-10')), $st['weeks'], true);

        foreach (['run', 'strength', 'combo', 'other'] as $bucket) {
            $this->assertSame(100, $st[$bucket][$week], "load bucket $bucket");
            $this->assertContains($bucket, array_column($te['groups'], 'bucket'), "scatter bucket $bucket");
        }
    }
}
