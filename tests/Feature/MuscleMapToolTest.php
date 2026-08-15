<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Garmin\GarminData;
use App\Garmin\MuscleFreshness;
use App\Mcp\Servers\GarminHealthServer;
use App\Mcp\Tools\GetMuscleMapTool;
use App\Models\ConnectorSettings;
use App\Models\McpToolCall;
use App\Models\SymptomLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * The muscle map over MCP. The one promise worth testing hard is
 * consistency: the tool must hand the chat the exact zones the dashboard
 * paints, computed by the same model from the same rows, and it must
 * refuse, abbreviate and withhold along the same switch lines as every
 * other tool.
 */
class MuscleMapToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Every installation has an owner, and the console paths
        // these tests drive (scheduled senders, stdio MCP) act for
        // that owner. See Tests\TestCase::athlete().
        $this->athlete();
    }

    /** A fully tracked squat session this many days back, at session time. */
    private function seedSquatSession(int $id, int $daysAgo, int $sets = 6): void
    {
        $date = date('Y-m-d', strtotime("-{$daysAgo} days"));
        $this->seedMirror('activities', [[
            'id' => $id,
            'date' => $date,
            'start_local' => $date.'T18:00:00.0',
            'type_key' => 'strength_training',
            'duration_s' => 3600.0,
            'training_load' => 120.0,
        ]]);
        $this->seedMirror('strength_sets', collect(range(1, $sets))->map(fn (int $i) => [
            'activity_id' => $id,
            'set_index' => $i,
            'exercise_category' => 'SQUAT',
            'set_type' => 'ACTIVE',
            'reps' => 5,
            'weight_g' => 80_000.0,
            'start_local' => $date.'T18:0'.($i % 10).':00.0',
        ])->all());
    }

    private function ask(array $arguments = []): TestResponse
    {
        return GarminHealthServer::tool(GetMuscleMapTool::class, $arguments);
    }

    /** The same answer as an array, for the arithmetic. */
    private function payload(): array
    {
        $response = (new GetMuscleMapTool)->execute(
            new Request([]), app(GarminData::class), app(MuscleFreshness::class),
        );

        return json_decode((string) $response->content(), true);
    }

    public function test_an_empty_mirror_is_said_rather_than_painted_fresh(): void
    {
        $this->ask()->assertSee('"has_data":false');
    }

    public function test_a_trained_zone_answers_with_the_models_own_numbers(): void
    {
        $this->seedSquatSession(1, 1);

        $payload = $this->payload();
        $quads = $payload['zones']['QUADRICEPS'];

        $this->assertTrue($quads['has_data']);
        // Trained yesterday at the hardest day of its own history, so the
        // zone cannot read fully fresh yet.
        $this->assertLessThan(100, $quads['freshness']);
        $this->assertSame(date('Y-m-d', strtotime('-1 day')), $quads['last_trained']);
        $this->assertGreaterThan(0, $quads['windows'][7]['fractional_sets']);
        $this->assertSame(100, $quads['windows'][7]['measured_share_pct']);
        $this->assertGreaterThan(0, $payload['volume_ceiling'][7]);

        // And the exact number the dashboard's model computes, no drift.
        $expected = app(MuscleFreshness::class)->compute(
            app(GarminData::class)->strengthSets(90),
            app(GarminData::class)->activities(400),
        );
        $this->assertSame($expected['zones']['QUADRICEPS']['freshness'], $quads['freshness']);
    }

    public function test_a_zone_the_mirror_never_loaded_says_no_data_not_fresh(): void
    {
        $this->seedSquatSession(1, 1);

        $forearm = $this->payload()['zones']['FOREARM'];

        $this->assertFalse($forearm['has_data']);
        $this->assertArrayNotHasKey('freshness', $forearm);
    }

    public function test_symptoms_ride_along_only_while_their_switch_is_on(): void
    {
        $this->seedSquatSession(1, 1);
        SymptomLog::create([
            'user_id' => $this->athlete()->id,
            'date' => date('Y-m-d'),
            'logged_at' => now(),
            'symptom' => 'sore knee',
            'body_zone' => 'KNEE',
            'side' => 'left',
            'severity' => 2,
        ]);

        $this->assertArrayHasKey('symptoms', $this->payload());

        ConnectorSettings::for($this->athlete())->update(['allow_symptoms' => false]);

        $this->assertArrayNotHasKey('symptoms', $this->payload());
    }

    public function test_the_permission_switch_refuses_by_its_page_name(): void
    {
        ConnectorSettings::for($this->athlete())->update(['share_health_data' => false]);

        $response = $this->ask();

        $response->assertSee('Read health data');
        $this->assertFalse(McpToolCall::sole()->ok);
    }
}
