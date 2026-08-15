<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The progression card on the training surface, through the real mirror
 * schema. The seeded rows keep the live mirror's shape: sets ride on
 * HIIT activities more often than on strength ones, reps are counted,
 * weights are mostly absent and the category is mostly UNKNOWN.
 */
class StrengthProgressionCardTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<int, array{cat: ?string, reps: ?int, weight: ?float, type?: string}>  $sets */
    private function seedSession(int $id, string $date, array $sets, string $type = 'hiit'): void
    {
        $this->seedMirror('activities', [[
            'id' => $id,
            'date' => $date,
            'start_local' => $date.'T17:00:00.0',
            'type_key' => $type,
            'duration_s' => 2400.0,
        ]]);
        $this->seedMirror('strength_sets', collect($sets)->map(fn (array $set, int $i) => [
            'activity_id' => $id,
            'set_index' => $i,
            'exercise_category' => $set['cat'],
            'set_type' => $set['type'] ?? 'ACTIVE',
            'reps' => $set['reps'],
            'weight_g' => $set['weight'],
            'start_local' => $date.'T17:0'.$i.':00.0',
        ])->all());
    }

    /** A date this many whole weeks back, so the week grid stays deterministic. */
    private function weeksAgo(int $weeks): string
    {
        return date('Y-m-d', strtotime('-'.(7 * $weeks).' days'));
    }

    public function test_the_card_renders_from_recorded_sets_and_names_its_limits(): void
    {
        // The mirror's usual shape: a circuit session whose sets carry reps but no
        // weight, most of them unclassified, plus a REST row.
        $this->seedSession(1, $this->weeksAgo(2), [
            ['cat' => 'UNKNOWN', 'reps' => 12, 'weight' => null],
            ['cat' => 'UNKNOWN', 'reps' => 10, 'weight' => 0.0],
            ['cat' => 'FLYE', 'reps' => 8, 'weight' => null],
            ['cat' => null, 'reps' => 0, 'weight' => null, 'type' => 'REST'],
        ]);
        $this->seedSession(2, $this->weeksAgo(1), [
            ['cat' => 'UNKNOWN', 'reps' => 15, 'weight' => null],
        ]);

        $this->actingAs($this->athlete())->get('/')
            ->assertOk()
            ->assertSee('Strength progression')
            ->assertSee('id="chart-strength-progress"', false)
            ->assertSee('Unclassified')
            ->assertSee('best week 22')
            // The card names both boundaries of this mirror: set-recorded
            // workouts only, and reps because no set carries a weight.
            ->assertSee('Only workouts the watch recorded set by set count here; a circuit session without set data is missing.')
            ->assertSee('No recorded set carries a weight in this window, so progression is counted in reps.')
            ->assertSee('The watch cannot classify most circuit movements; they land in Unclassified.');
    }

    public function test_weighted_categories_answer_in_kilograms_with_the_stagnation_reading(): void
    {
        foreach ([4, 3, 2, 1] as $week) {
            $this->seedSession($week, $this->weeksAgo($week), [
                ['cat' => 'SQUAT', 'reps' => 5, 'weight' => 80_000.0],
            ], type: 'strength_training');
        }

        $this->actingAs($this->athlete())->get('/')
            ->assertOk()
            ->assertSee('top 80 kg')
            ->assertSee('Top weight steady at 80 kg for 4 weeks')
            ->assertDontSee('No recorded set carries a weight in this window');
    }

    public function test_the_card_disappears_honestly_when_the_mirror_holds_no_sets(): void
    {
        $this->actingAs($this->athlete())->get('/')
            ->assertOk()
            ->assertDontSee('Strength progression')
            ->assertDontSee('id="chart-strength-progress"', false);
    }

    public function test_the_range_endpoint_carries_the_chart_and_the_row_fragment(): void
    {
        $this->seedSession(1, $this->weeksAgo(1), [
            ['cat' => 'UNKNOWN', 'reps' => 12, 'weight' => null],
        ]);

        $response = $this->actingAs($this->athlete())
            ->getJson('/api/dashboard/charts?days=30')
            ->assertOk()
            ->assertJsonStructure(['charts' => ['strengthProgress' => ['weeks', 'series', 'runningIndex']]]);

        $this->assertSame('Unclassified', $response->json('charts.strengthProgress.series.0.label'));
        $this->assertStringContainsString('data-kpi="strengthProgress"', $response->json('kpi.strengthProgress'));
    }
}
