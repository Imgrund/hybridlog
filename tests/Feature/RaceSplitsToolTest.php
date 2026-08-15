<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Garmin\GarminData;
use App\Mcp\Servers\GarminHealthServer;
use App\Mcp\Tools\GetRaceSplitsTool;
use App\Models\ConnectorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * The lap breakdown over MCP. A race that alternates running with station
 * work is the shape this was built for, and the classification has to come
 * off the measurement rather than off an assumption about the sport: a lap
 * that covered distance ran, a lap that covered none did not.
 */
class RaceSplitsToolTest extends TestCase
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
        $response = (new GetRaceSplitsTool)->execute(
            new Request($arguments), app(GarminData::class),
        );

        return json_decode((string) $response->content(), true);
    }

    /**
     * Eight runs of a kilometre with a station between each: the race this
     * tool exists for, seeded the way Garmin records it.
     *
     * @param  list<array{0: float, 1: float}>  $runs  duration and moving seconds per running lap
     */
    private function seedRace(int $id, array $runs, float $stationSeconds = 240.0): void
    {
        $date = date('Y-m-d', strtotime('-2 days'));

        $this->seedMirror('activities', [[
            'id' => $id,
            'date' => $date,
            'start_local' => $date.'T09:00:00.0',
            'type_key' => 'running',
            'name' => 'Race simulation',
            'duration_s' => 5400.0,
        ]]);

        $laps = [];
        $index = 1;
        foreach ($runs as [$duration, $moving]) {
            $laps[] = [
                'activity_id' => $id,
                'lap_index' => $index++,
                'duration_s' => $duration,
                'moving_s' => $moving,
                'distance_m' => 1000.0,
                'avg_hr' => 168,
            ];
            // Garmin writes the station as distance 0.0 and no motion,
            // which is the measurement this tool reads the kind off.
            $laps[] = [
                'activity_id' => $id,
                'lap_index' => $index++,
                'duration_s' => $stationSeconds,
                'moving_s' => 0.0,
                'distance_m' => 0.0,
                'avg_hr' => 172,
            ];
        }

        $this->seedMirror('activity_laps', $laps);
    }

    public function test_it_reads_the_kind_off_the_distance_not_off_the_sport(): void
    {
        $this->seedRace(11, [[300.0, 300.0], [310.0, 305.0], [320.0, 320.0], [330.0, 325.0]]);

        $payload = $this->payload();

        $this->assertTrue($payload['has_data']);
        $this->assertSame(8, $payload['activity']['lap_count']);

        $kinds = array_column($payload['segments'], 'kind');
        $this->assertSame(
            ['run', 'station', 'run', 'station', 'run', 'station', 'run', 'station'],
            $kinds,
        );

        // A station lap has no pace: dividing its seconds by the metres it
        // did not cover would be an invented number, so the key is absent.
        $this->assertArrayNotHasKey('pace_s_per_km', $payload['segments'][1]);
        $this->assertSame(300, $payload['segments'][0]['pace_s_per_km']);
    }

    public function test_it_reports_how_far_the_pace_drifted(): void
    {
        // 300 s/km opening, 330 s/km closing: ten percent slower on tired
        // legs, which is the number the athlete came for.
        $this->seedRace(12, [[300.0, 300.0], [310.0, 310.0], [320.0, 320.0], [330.0, 330.0]]);

        $running = $this->payload()['running'];

        $this->assertSame(4, $running['laps']);
        $this->assertSame(300, $running['first_lap_pace_s_per_km']);
        $this->assertSame(330, $running['last_lap_pace_s_per_km']);
        $this->assertSame(300, $running['fastest_lap_pace_s_per_km']);
        $this->assertSame(330, $running['slowest_lap_pace_s_per_km']);
        $this->assertEqualsWithDelta(10.0, $running['pace_drift_pct'], 0.05);
    }

    public function test_it_splits_the_clock_between_running_stations_and_standing_still(): void
    {
        // Two runs of 300 s that moved for only 280, plus two 240 s
        // stations that moved not at all.
        $this->seedRace(13, [[300.0, 280.0], [300.0, 280.0]], stationSeconds: 240.0);

        $totals = $this->payload()['totals'];

        $this->assertEqualsWithDelta(1080.0, $totals['elapsed_s'], 0.05);
        $this->assertEqualsWithDelta(600.0, $totals['run_s'], 0.05);
        $this->assertEqualsWithDelta(480.0, $totals['station_s'], 0.05);
        // 20 s standing inside each run, plus the two stations that never
        // moved at all.
        $this->assertEqualsWithDelta(520.0, $totals['non_moving_s'], 0.05);
        $this->assertEqualsWithDelta(2000.0, $totals['run_distance_m'], 0.05);
        $this->assertEqualsWithDelta(55.6, $totals['run_share_pct'], 0.05);
    }

    public function test_a_named_activity_wins_over_the_most_recent_one(): void
    {
        $this->seedRace(21, [[300.0, 300.0], [305.0, 305.0]]);
        $this->seedRace(22, [[280.0, 280.0], [285.0, 285.0]]);

        $this->assertSame(21, $this->payload(['activity_id' => 21])['activity']['id']);
        $this->assertSame(22, $this->payload(['activity_id' => 22])['activity']['id']);
    }

    public function test_it_separates_no_laps_anywhere_from_no_laps_here(): void
    {
        // Nothing backfilled yet: the fetcher's problem, not the athlete's
        // recording.
        $empty = $this->payload();
        $this->assertFalse($empty['has_data']);
        $this->assertStringContainsString('no lap data at all', $empty['hint']);

        // Laps exist, just not enough on any one session.
        $this->seedRace(31, [[300.0, 300.0]]);
        $thin = $this->payload(['min_laps' => 6]);
        $this->assertFalse($thin['has_data']);
        $this->assertStringContainsString('at least 6 laps', $thin['hint']);
    }

    public function test_the_health_data_switch_closes_it(): void
    {
        $this->seedRace(41, [[300.0, 300.0], [310.0, 310.0]]);

        ConnectorSettings::for($this->athlete())->update(['share_health_data' => false]);

        GarminHealthServer::tool(GetRaceSplitsTool::class)->assertHasErrors();
    }
}
