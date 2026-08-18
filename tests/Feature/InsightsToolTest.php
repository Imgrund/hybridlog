<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Garmin\GarminData;
use App\Garmin\Insights;
use App\Garmin\TrainingLoad;
use App\Mcp\Servers\GarminHealthServer;
use App\Mcp\Tools\GetInsightsTool;
use App\Models\ConnectorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * The app's own verdict over MCP. The point of this tool is that chat and
 * dashboard cannot disagree about whether somebody is coming down with
 * something, so the tests assert the rules fire the same way through the
 * tool as they do through the page, and that the body-metrics switch
 * closes exactly the one system built from weight and fitness age.
 */
class InsightsToolTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete();
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $response = (new GetInsightsTool)->execute(
            new Request([]), app(GarminData::class), app(Insights::class), app(TrainingLoad::class),
        );

        return json_decode((string) $response->content(), true);
    }

    /**
     * Thirty days of quiet, then whatever the caller wants on top. The
     * baseline skips the last two days by design, so an onset seeded into
     * them cannot pull the baseline after it.
     *
     * @param  array{rhr?: int, resp?: float, hrvNight?: float}  $today
     */
    private function seedHistory(array $today = []): void
    {
        $days = [];
        $sleep = [];
        for ($back = 40; $back >= 0; $back--) {
            $date = date('Y-m-d', strtotime("-{$back} days"));
            $isToday = $back === 0;

            $rhr = $isToday ? ($today['rhr'] ?? 48) : 48;
            $days[] = [
                'date' => $date,
                'resting_hr' => $rhr,
                // Coupled: a settled reading sits near the day's floor,
                // and Insights reads today's value out where it does not.
                'min_hr' => $rhr - 4,
                'max_hr' => 160,
                'vo2max_running' => 52.0,
            ];
            $sleep[] = [
                'date' => $date,
                // A steady window: bedtime 22:30, up at 06:30.
                'start_local' => date('Y-m-d', strtotime("-{$back} days -1 day")).' 22:30:00',
                'end_local' => $date.' 06:30:00',
                'duration_s' => 8 * 3600,
                'score' => 82,
                'respiration_avg' => $isToday ? ($today['resp'] ?? 13.0) : 13.0,
            ];
        }

        $this->seedMirror('days', $days);
        $this->seedMirror('sleep', $sleep);
        $this->seedMirror('hrv', [[
            'date' => date('Y-m-d'),
            'last_night_avg' => $today['hrvNight'] ?? 70.0,
            'weekly_avg' => 72.0,
            'status' => 'BALANCED',
            'baseline_balanced_low' => 60.0,
            'baseline_balanced_upper' => 85.0,
        ]]);
    }

    public function test_it_returns_the_four_body_systems_with_a_status_and_a_recommendation(): void
    {
        $this->seedHistory();

        $payload = $this->payload();

        $this->assertTrue($payload['has_data']);
        foreach (['heart', 'head', 'lungs', 'core'] as $system) {
            $this->assertArrayHasKey($system, $payload['systems']);
            $this->assertContains($payload['systems'][$system]['status'], ['good', 'warning', 'serious', 'critical']);
            $this->assertNotEmpty($payload['systems'][$system]['recommendation']);
        }

        // Quiet history, quiet verdict, and no pattern to warn about.
        $this->assertSame('good', $payload['systems']['heart']['status']);
        $this->assertArrayNotHasKey('illness_pattern', $payload);
    }

    public function test_the_illness_pattern_fires_and_raises_the_systems_it_is_made_of(): void
    {
        // Resting heart rate 8 bpm over its own baseline, breathing 3
        // breaths over, HRV a fifth under the weekly average: all three
        // criteria, which is the serious rung.
        $this->seedHistory(['rhr' => 56, 'resp' => 16.0, 'hrvNight' => 55.0]);

        $payload = $this->payload();

        $this->assertSame('serious', $payload['illness_pattern']['status']);
        $this->assertSame(['rhr', 'resp', 'hrv'], $payload['illness_pattern']['criteria_met']);

        // The pattern is hung into the systems it is built from rather
        // than left standing beside them.
        $this->assertSame('serious', $payload['systems']['lungs']['status']);
        $this->assertArrayHasKey('Illness pattern', $payload['systems']['heart']['facts']);
    }

    public function test_the_body_metrics_switch_closes_the_metabolism_system_only(): void
    {
        $this->seedHistory();
        $this->seedMirror('fitness_age', [[
            'date' => date('Y-m-d'),
            'chronological_age' => 38.0,
            'fitness_age' => 31.0,
        ]]);

        $this->assertArrayHasKey('metabolism', $this->payload()['systems']);

        ConnectorSettings::for($this->athlete())->update(['share_body_metrics' => false]);

        $closed = $this->payload();
        $this->assertArrayNotHasKey('metabolism', $closed['systems']);
        // The other four keep answering: this switch is about the body, not
        // about the whole connection.
        $this->assertArrayHasKey('heart', $closed['systems']);
    }

    public function test_an_empty_mirror_says_so_instead_of_inventing_a_verdict(): void
    {
        $payload = $this->payload();

        $this->assertFalse($payload['has_data']);
        $this->assertStringContainsString('neither daily metrics nor nights', $payload['hint']);
    }

    public function test_the_health_data_switch_closes_it(): void
    {
        $this->seedHistory();

        ConnectorSettings::for($this->athlete())->update(['share_health_data' => false]);

        GarminHealthServer::tool(GetInsightsTool::class)->assertHasErrors();
    }
}
