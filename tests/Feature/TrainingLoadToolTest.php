<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Garmin\GarminData;
use App\Garmin\TrainingLoad;
use App\Mcp\Servers\GarminHealthServer;
use App\Mcp\Tools\GetTrainingLoadTool;
use App\Models\ConnectorSettings;
use App\Models\McpToolCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\TestCase;

/**
 * The load model over MCP. Same promise as the muscle map tool: the chat
 * gets the dashboard's own CTL/ATL/TSB and ratio, never a re-derivation,
 * and the answer names where the model still warms up.
 */
class TrainingLoadToolTest extends TestCase
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

    private function seedActivity(int $id, int $daysAgo, string $type = 'running', float $load = 100.0): void
    {
        $date = date('Y-m-d', strtotime("-{$daysAgo} days"));
        $this->seedMirror('activities', [[
            'id' => $id,
            'date' => $date,
            'start_local' => $date.'T17:00:00.0',
            'type_key' => $type,
            'duration_s' => 3000.0,
            'training_load' => $load,
        ]]);
    }

    private function ask(array $arguments = []): TestResponse
    {
        return GarminHealthServer::tool(GetTrainingLoadTool::class, $arguments);
    }

    /** The same answer as an array, for the arithmetic. */
    private function payload(array $arguments = []): array
    {
        $response = (new GetTrainingLoadTool)->execute(
            new Request($arguments), app(GarminData::class), app(TrainingLoad::class),
        );

        return json_decode((string) $response->content(), true);
    }

    public function test_an_empty_mirror_is_said_rather_than_modelled(): void
    {
        $this->ask()->assertSee('"has_data":false');
    }

    public function test_the_model_answers_with_the_dashboards_own_numbers(): void
    {
        foreach ([1, 3, 5, 10, 20] as $i => $daysAgo) {
            $this->seedActivity($i + 1, $daysAgo);
        }

        $payload = $this->payload();

        $this->assertGreaterThan(0, $payload['current']['ctl_fitness']);
        $this->assertGreaterThan(0, $payload['current']['atl_fatigue']);
        // Default window: 28 trailing days plus today.
        $this->assertCount(29, $payload['series']['dates']);
        $this->assertSame(date('Y-m-d'), $payload['current']['date']);

        // The exact series the dashboard computes from the same rows; the
        // requested window may differ, today's values may not.
        $expected = app(TrainingLoad::class)->series(app(GarminData::class)->activities(400), 120);
        $this->assertSame(end($expected['ctl']), $payload['current']['ctl_fitness']);
        $this->assertSame(end($expected['atl']), $payload['current']['atl_fatigue']);
        $this->assertSame($expected['modelStart'], $payload['model']['model_start']);
    }

    public function test_garmins_own_ratio_wins_over_the_computed_one(): void
    {
        $this->seedActivity(1, 2);
        $this->seedMirror('training_status', [[
            'date' => date('Y-m-d', strtotime('-1 day')),
            'acwr' => 1.1,
            'acute_load' => 350.0,
            'chronic_load' => 320.0,
        ]]);

        $acwr = $this->payload()['current']['acwr'];

        $this->assertSame('garmin', $acwr['source']);
        $this->assertSame(1.1, $acwr['value']);
        $this->assertSame('good', $acwr['status']);
    }

    public function test_the_stimulus_split_buckets_like_the_weekly_load_card(): void
    {
        // Circuit work is strength, a run is a run, this week, so both land in
        // the newest stimulus row.
        $this->seedActivity(1, 0, 'hiit', 80.0);
        $this->seedActivity(2, 0, 'running', 60.0);

        $weeks = $this->payload()['stimulus_weeks'];
        $current = end($weeks);

        $this->assertSame(80.0, (float) $current['strength']);
        $this->assertSame(60.0, (float) $current['run']);
        $this->assertSame(140.0, (float) $current['total']);
    }

    public function test_the_series_window_is_the_callers_choice(): void
    {
        $this->seedActivity(1, 2);

        $this->assertCount(8, $this->payload(['series_days' => 7])['series']['dates']);
    }

    public function test_the_permission_switch_refuses_by_its_page_name(): void
    {
        ConnectorSettings::for($this->athlete())->update(['share_health_data' => false]);

        $response = $this->ask();

        $response->assertSee('Read health data');
        $this->assertFalse(McpToolCall::sole()->ok);
    }
}
