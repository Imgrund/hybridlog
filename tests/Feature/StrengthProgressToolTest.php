<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Garmin\GarminData;
use App\Garmin\StrengthProgression;
use App\Mcp\Servers\GarminHealthServer;
use App\Mcp\Tools\GetStrengthProgressTool;
use App\Models\ConnectorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * Strength progression over MCP. The one thing this must never do is turn
 * a weightless rep into a kilogram: the watch counts reps for almost every
 * set and carries a weight for almost none, so a category without recorded
 * weights reports no tonnage at all rather than a curve built from the odd
 * logged dumbbell.
 */
class StrengthProgressToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete();
    }

    /** @return array<string, mixed> */
    private function payload(array $arguments = []): array
    {
        $response = (new GetStrengthProgressTool)->execute(
            new Request($arguments), app(GarminData::class), app(StrengthProgression::class),
        );

        return json_decode((string) $response->content(), true);
    }

    /**
     * One session per week over the last four complete weeks, each with
     * three sets of the same category.
     *
     * @param  list<?float>  $weeklyKg  the weight each week's sets carry, null for none
     */
    private function seedWeeks(string $category, array $weeklyKg, int $firstActivityId = 100): void
    {
        $activities = [];
        $sets = [];
        $setIndex = 0;

        foreach ($weeklyKg as $offset => $kg) {
            // Newest week last: index 0 is the oldest of the seeded weeks.
            $weeksBack = count($weeklyKg) - $offset;
            $date = date('Y-m-d', strtotime("monday this week -{$weeksBack} weeks +2 days"));
            $id = $firstActivityId + $offset;

            $activities[] = [
                'id' => $id,
                'date' => $date,
                'start_local' => $date.'T18:00:00.0',
                'type_key' => 'strength_training',
                'name' => 'Strength',
                'duration_s' => 3600.0,
            ];

            for ($set = 0; $set < 3; $set++) {
                $sets[] = [
                    'activity_id' => $id,
                    'set_index' => $setIndex++,
                    'exercise_category' => $category,
                    'set_type' => 'ACTIVE',
                    'reps' => 5,
                    'weight_g' => $kg === null ? null : $kg * 1000,
                    'start_local' => $date.'T18:0'.$set.':00',
                ];
            }
        }

        $this->seedMirror('activities', $activities);
        $this->seedMirror('strength_sets', $sets);
    }

    public function test_it_reports_reps_tonnage_and_the_weekly_top_weight(): void
    {
        $this->seedWeeks('BENCH_PRESS', [60.0, 62.5, 65.0, 67.5]);

        $payload = $this->payload(['weeks' => 6]);
        $this->assertTrue($payload['has_data']);

        $bench = $payload['categories'][0];
        $this->assertSame('Bench press', $bench['category']);
        $this->assertSame('BENCH_PRESS', $bench['category_key']);
        $this->assertTrue($bench['weight_tracked']);
        $this->assertSame(12, $bench['sets']);

        // The grid reaches from the first session to the running week, so
        // it is four trained weeks plus the current one. Every per-week
        // array is aligned with it, which is what the payload promises.
        $this->assertSame([...$payload['weeks']][4], $payload['running_week']);
        $this->assertCount(count($payload['weeks']), $bench['reps_per_week']);
        $this->assertCount(count($payload['weeks']), $bench['top_kg_per_week']);
        $this->assertSame(15, max($bench['reps_per_week']));

        $this->assertEqualsWithDelta(67.5, $bench['best_set_kg'], 0.01);
        // Three sets of five at 67.5 kg is the heaviest week: 1012.5 kg.
        $this->assertEqualsWithDelta(1012.5, $bench['best_week_kg'], 0.01);
    }

    public function test_a_category_without_recorded_weights_reports_no_tonnage(): void
    {
        // What circuit work looks like in this mirror: reps counted, no
        // weight anywhere, and Garmin unable to name the movement.
        $this->seedWeeks('UNKNOWN', [null, null, null, null]);

        $unknown = $this->payload(['weeks' => 6])['categories'][0];

        $this->assertSame('Unclassified', $unknown['category']);
        $this->assertFalse($unknown['weight_tracked']);
        $this->assertArrayNotHasKey('kg_volume_per_week', $unknown);
        $this->assertArrayNotHasKey('best_set_kg', $unknown);
        // Not even an all-null weekly top: a row of nulls is not a series.
        $this->assertArrayNotHasKey('top_kg_per_week', $unknown);
        // The reps happened and are reported: no weight is not no work.
        $this->assertSame(15, max($unknown['reps_per_week']));
        $this->assertFalse($this->payload(['weeks' => 6])['any_weight_recorded']);
    }

    public function test_it_names_a_top_weight_that_has_not_moved(): void
    {
        $this->seedWeeks('SQUAT', [100.0, 100.0, 100.0, 100.0]);

        $squat = $this->payload(['weeks' => 6])['categories'][0];

        $this->assertEqualsWithDelta(100.0, $squat['holds_at']['kg'], 0.01);
        $this->assertGreaterThanOrEqual(3, $squat['holds_at']['weeks']);
    }

    public function test_an_empty_mirror_points_at_the_load_model_instead(): void
    {
        $payload = $this->payload();

        $this->assertFalse($payload['has_data']);
        $this->assertStringContainsString('get-training-load-tool', $payload['hint']);
    }

    public function test_the_health_data_switch_closes_it(): void
    {
        $this->seedWeeks('SQUAT', [100.0, 102.5]);

        ConnectorSettings::for($this->athlete())->update(['share_health_data' => false]);

        GarminHealthServer::tool(GetStrengthProgressTool::class)->assertHasErrors();
    }
}
