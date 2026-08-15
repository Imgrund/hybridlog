<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Garmin\StrengthProgression;
use App\View\Dashboard\ChartBundle;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Pins the weekly progression model against known sets. The fixtures
 * follow the mirror's real shape: reps on almost every active set,
 * weight_g mostly NULL or 0 (verified against two months of live data),
 * category mostly UNKNOWN, REST rows without a category. The weighted
 * cases exist because a set CAN carry a weight, not because this mirror
 * usually does.
 */
class StrengthProgressionTest extends TestCase
{
    /** One set row in the shape GarminData::strengthSets() hands over. */
    private function set(
        string $date,
        ?string $category,
        ?int $reps,
        ?float $weightG = null,
        string $type = 'ACTIVE',
        int $activityId = 1,
    ): object {
        return (object) [
            'activity_id' => $activityId,
            'exercise_category' => $category,
            'set_type' => $type,
            'reps' => $reps,
            'weight_g' => $weightG,
            'activity_date' => $date,
        ];
    }

    /** A date this many whole weeks back, so the week grid stays deterministic. */
    private function weeksAgo(int $weeks): string
    {
        return date('Y-m-d', strtotime('-'.(7 * $weeks).' days'));
    }

    private function weekly(array $sets, int $lastWeeks = 8): array
    {
        return (new StrengthProgression)->weekly(collect($sets), $lastWeeks);
    }

    public function test_weekly_reps_fill_the_grid_and_the_running_week_is_marked(): void
    {
        $model = $this->weekly([
            $this->set($this->weeksAgo(3), 'UNKNOWN', 10, activityId: 1),
            $this->set($this->weeksAgo(3), 'UNKNOWN', 12, activityId: 1),
            // The gap week in between must come out as a zero, not vanish.
            $this->set($this->weeksAgo(1), 'UNKNOWN', 8, activityId: 2),
            $this->set($this->weeksAgo(0), 'UNKNOWN', 5, activityId: 3),
        ]);

        $this->assertCount(4, $model['weeks']);
        $this->assertSame(3, $model['runningIndex']);
        $this->assertSame(3, $model['sessions']);

        $unknown = $model['categories'][0];
        $this->assertSame('UNKNOWN', $unknown['key']);
        $this->assertSame([22, 0, 8, 5], $unknown['reps']);
        $this->assertFalse($unknown['weighted']);
        $this->assertNull($unknown['kg']);
        $this->assertSame(22, $unknown['bestWeekReps']);
        $this->assertSame(8, $unknown['lastFullWeekReps']);
        $this->assertNull($unknown['stagnation']);
    }

    public function test_rest_sets_null_reps_and_empty_categories_are_handled(): void
    {
        $model = $this->weekly([
            // REST rows carry no category in the mirror and never count.
            $this->set($this->weeksAgo(1), null, 0, type: 'REST'),
            // An active set without a category folds into UNKNOWN.
            $this->set($this->weeksAgo(1), null, 6),
            // A null rep count adds no invented volume.
            $this->set($this->weeksAgo(1), 'UNKNOWN', null),
        ]);

        $this->assertCount(1, $model['categories']);
        $unknown = $model['categories'][0];
        $this->assertSame('UNKNOWN', $unknown['key']);
        $this->assertSame(2, $unknown['sets']);
        $this->assertSame(6, $unknown['bestWeekReps']);
    }

    public function test_an_empty_mirror_yields_an_empty_model(): void
    {
        $this->assertSame(
            ['weeks' => [], 'runningIndex' => null, 'sessions' => 0, 'anyWeight' => false, 'categories' => []],
            $this->weekly([]),
        );
    }

    public function test_tonnage_exists_only_where_the_majority_of_sets_carries_a_weight(): void
    {
        $model = $this->weekly([
            // Two of three squat sets carry a weight: tonnage is real.
            $this->set($this->weeksAgo(1), 'SQUAT', 5, 80_000.0),
            $this->set($this->weeksAgo(1), 'SQUAT', 5, 60_000.0),
            $this->set($this->weeksAgo(1), 'SQUAT', 10, null),
            // One of three row sets does: reps volume, no kilogram curve,
            // but the recorded weight facts stay readable.
            $this->set($this->weeksAgo(1), 'ROW', 10, 40_000.0),
            $this->set($this->weeksAgo(1), 'ROW', 10, null),
            $this->set($this->weeksAgo(1), 'ROW', 10, 0.0),
        ]);

        $this->assertTrue($model['anyWeight']);

        [$row, $squat] = $model['categories'];
        $this->assertSame('ROW', $row['key']);

        $this->assertTrue($squat['weighted']);
        // 5 x 80 kg + 5 x 60 kg; the weightless set adds reps, never kilograms.
        $this->assertSame([700.0, 0.0], $squat['kg']);
        $this->assertSame([20, 0], $squat['reps']);
        $this->assertSame(80.0, $squat['currentTopKg']);
        $this->assertSame(80.0, $squat['bestSetKg']);
        $this->assertSame(700.0, $squat['bestWeekKg']);

        $this->assertFalse($row['weighted']);
        $this->assertNull($row['kg']);
        $this->assertSame([30, 0], $row['reps']);
        $this->assertSame(40.0, $row['bestSetKg']);
    }

    public function test_categories_are_ordered_by_windowed_set_count(): void
    {
        $model = $this->weekly([
            $this->set($this->weeksAgo(1), 'FLYE', 8),
            $this->set($this->weeksAgo(1), 'UNKNOWN', 4),
            $this->set($this->weeksAgo(1), 'UNKNOWN', 4),
            // Outside the sliced window: must not count into the ranking.
            $this->set($this->weeksAgo(6), 'FLYE', 8),
            $this->set($this->weeksAgo(6), 'FLYE', 8),
            $this->set($this->weeksAgo(6), 'FLYE', 8),
        ], lastWeeks: 3);

        $this->assertSame(['UNKNOWN', 'FLYE'], array_column($model['categories'], 'key'));
    }

    public function test_stagnation_fires_on_a_regular_unchanged_top(): void
    {
        $sets = [];
        foreach ([4, 3, 2, 1] as $week) {
            $sets[] = $this->set($this->weeksAgo($week), 'SQUAT', 5, 80_000.0, activityId: $week);
        }

        $squat = $this->weekly($sets)['categories'][0];

        $this->assertSame(['weeks' => 4, 'kg' => 80.0], $squat['stagnation']);
    }

    public function test_stagnation_bridges_a_single_skipped_week(): void
    {
        $sets = [];
        foreach ([4, 3, 1] as $week) {
            $sets[] = $this->set($this->weeksAgo($week), 'SQUAT', 5, 80_000.0, activityId: $week);
        }

        $squat = $this->weekly($sets)['categories'][0];

        // Three trained weeks, the top unmoved across the four-week span.
        $this->assertSame(['weeks' => 4, 'kg' => 80.0], $squat['stagnation']);
    }

    public function test_stagnation_stays_silent_when_the_top_moved(): void
    {
        $sets = [
            $this->set($this->weeksAgo(4), 'SQUAT', 5, 80_000.0, activityId: 4),
            $this->set($this->weeksAgo(3), 'SQUAT', 5, 80_000.0, activityId: 3),
            $this->set($this->weeksAgo(2), 'SQUAT', 5, 80_000.0, activityId: 2),
            // Progress in the newest complete week is change, not a plateau.
            $this->set($this->weeksAgo(1), 'SQUAT', 5, 85_000.0, activityId: 1),
        ];

        $this->assertNull($this->weekly($sets)['categories'][0]['stagnation']);
    }

    public function test_stagnation_stays_silent_when_training_is_irregular(): void
    {
        $sets = [
            $this->set($this->weeksAgo(4), 'SQUAT', 5, 80_000.0, activityId: 4),
            $this->set($this->weeksAgo(1), 'SQUAT', 5, 80_000.0, activityId: 1),
        ];

        // Two trained weeks in the last four is a pause, not a plateau.
        $this->assertNull($this->weekly($sets)['categories'][0]['stagnation']);
    }

    public function test_stagnation_stays_silent_for_weightless_categories(): void
    {
        $sets = [];
        foreach ([4, 3, 2, 1] as $week) {
            $sets[] = $this->set($this->weeksAgo($week), 'UNKNOWN', 10, activityId: $week);
        }

        $this->assertNull($this->weekly($sets)['categories'][0]['stagnation']);
    }

    public function test_stagnation_ends_when_the_running_week_lifts_a_different_top(): void
    {
        $sets = [];
        foreach ([4, 3, 2, 1] as $week) {
            $sets[] = $this->set($this->weeksAgo($week), 'SQUAT', 5, 80_000.0, activityId: $week);
        }
        $sets[] = $this->set($this->weeksAgo(0), 'SQUAT', 3, 85_000.0, activityId: 99);

        $this->assertNull($this->weekly($sets)['categories'][0]['stagnation']);
    }

    public function test_category_labels_speak_the_mirrors_vocabulary(): void
    {
        $this->assertSame('Unclassified', StrengthProgression::label('UNKNOWN'));
        $this->assertSame('Triceps extension', StrengthProgression::label('TRICEPS_EXTENSION'));
        $this->assertSame('Squat', StrengthProgression::label('SQUAT'));
    }

    public function test_the_chart_stack_names_three_categories_and_folds_the_rest(): void
    {
        $model = $this->weekly([
            $this->set($this->weeksAgo(1), 'UNKNOWN', 4),
            $this->set($this->weeksAgo(1), 'UNKNOWN', 4),
            $this->set($this->weeksAgo(1), 'UNKNOWN', 4),
            $this->set($this->weeksAgo(1), 'UNKNOWN', 4),
            $this->set($this->weeksAgo(1), 'FLYE', 8),
            $this->set($this->weeksAgo(1), 'FLYE', 8),
            $this->set($this->weeksAgo(1), 'SQUAT', 5),
            $this->set($this->weeksAgo(1), 'SQUAT', 5),
            $this->set($this->weeksAgo(1), 'ROW', 20),
            $this->set($this->weeksAgo(1), 'SIT_UP', 15),
        ]);

        $reflected = new ReflectionMethod(ChartBundle::class, 'strengthProgressChart');
        $reflected->setAccessible(true);
        $chart = $reflected->invoke(app(ChartBundle::class), $model);

        $this->assertSame($model['weeks'], $chart['weeks']);
        $this->assertSame(
            ['Unclassified', 'Flye', 'Squat', 'Other'],
            array_column($chart['series'], 'label'),
        );
        $this->assertSame([false, false, false, true], array_column($chart['series'], 'other'));
        // The fold is the element-wise sum of everything unnamed.
        $this->assertSame([35, 0], end($chart['series'])['reps']);
    }
}
