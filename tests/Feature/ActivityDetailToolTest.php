<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Garmin\GarminData;
use App\Mcp\Servers\GarminHealthServer;
use App\Mcp\Tools\GetActivityDetailTool;
use App\Models\ConnectorSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Tests\TestCase;

/**
 * One session in depth over MCP. The numbers a chat used to rebuild in
 * SQL, zones above all, have to come out of the mirror's own columns and
 * the athlete's own zone profile, and the three states of a session's
 * zones (not fetched, none at Garmin, present) must stay three answers.
 */
class ActivityDetailToolTest extends TestCase
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
        $response = (new GetActivityDetailTool)->execute(
            new Request($arguments), app(GarminData::class),
        );

        return json_decode((string) $response->content(), true);
    }

    /**
     * One circuit session at 18:00 on a day in the past, written the way
     * the fetcher writes it. Returns the date it landed on.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function seedSession(int $id, int $daysAgo, array $overrides = []): string
    {
        $date = date('Y-m-d', strtotime("-{$daysAgo} days"));

        $this->seedMirror('activities', [array_merge([
            'id' => $id,
            'date' => $date,
            'start_local' => $date.' 18:00:00',
            'type_key' => 'hiit',
            'name' => 'Circuit',
            'duration_s' => 3600.0,
            'avg_hr' => 140,
            'max_hr' => 175,
            'training_load' => 120.0,
        ], $overrides)]);

        return $date;
    }

    public function test_it_reads_the_most_recent_session_without_an_id(): void
    {
        $this->seedSession(1, 3);
        $date = $this->seedSession(2, 1, ['name' => 'Yesterday']);

        $payload = $this->payload();

        $this->assertTrue($payload['has_data']);
        $this->assertSame(2, $payload['activity']['id']);
        $this->assertSame('Yesterday', $payload['activity']['name']);
        // The end is start plus duration: the window the curve is cut on.
        $this->assertSame($date.' 19:00:00', $payload['activity']['end_local']);
        // A session without distance has no pace, and no key pretending
        // to one.
        $this->assertArrayNotHasKey('pace_s_per_km', $payload['activity']);
    }

    public function test_it_turns_zone_seconds_into_minutes_shares_and_hard_minutes(): void
    {
        $this->seedSession(10, 2, [
            'hr_zones_json' => '{"1": 600.0, "2": 1200.0, "3": 600.0, "4": 900.0, "5": 300.0}',
        ]);

        // Two profiles: the one in force on the session's date, and a
        // newer one from after it that must not rewrite the session.
        $this->seedMirror('heart_profile', [
            ['date' => date('Y-m-d', strtotime('-10 days')), 'max_hr' => 190, 'lthr_bpm' => 168, 'zone1_floor' => 95, 'zone2_floor' => 114, 'zone3_floor' => 133, 'zone4_floor' => 152, 'zone5_floor' => 171],
            ['date' => date('Y-m-d'), 'max_hr' => 192, 'lthr_bpm' => 170, 'zone1_floor' => 96, 'zone2_floor' => 115, 'zone3_floor' => 134, 'zone4_floor' => 160, 'zone5_floor' => 175],
        ]);

        $zones = $this->payload(['activity_id' => 10])['hr_zones'];

        // Whole numbers travel through JSON without their fraction, so
        // the float assertions are tolerant rather than identical.
        $this->assertTrue($zones['has_data']);
        $this->assertEqualsWithDelta(15.0, $zones['zones']['zone4']['minutes'], 0.05);
        $this->assertEqualsWithDelta(25.0, $zones['zones']['zone4']['share_pct'], 0.05);
        $this->assertEqualsWithDelta(5.0, $zones['zones']['zone5']['minutes'], 0.05);
        $this->assertEqualsWithDelta(3600.0, $zones['covered_s'], 0.05);
        $this->assertEqualsWithDelta(20.0, $zones['hard_minutes'], 0.05);
        $this->assertSame(152, $zones['profile']['zone4_floor']);
        $this->assertSame(date('Y-m-d', strtotime('-10 days')), $zones['profile']['profile_date']);
    }

    public function test_it_tells_zones_not_fetched_yet_from_zones_garmin_never_had(): void
    {
        $this->seedSession(20, 2, ['hr_zones_json' => null]);
        $this->seedSession(21, 1, ['hr_zones_json' => '{}']);

        $pending = $this->payload(['activity_id' => 20])['hr_zones'];
        $this->assertFalse($pending['has_data']);
        $this->assertStringContainsString('not read the zones', $pending['hint']);

        $none = $this->payload(['activity_id' => 21])['hr_zones'];
        $this->assertFalse($none['has_data']);
        $this->assertStringContainsString('no heart-rate zones', $none['hint']);
    }

    public function test_it_compares_with_earlier_sessions_of_the_same_kind(): void
    {
        $this->seedSession(30, 1, ['training_load' => 150.0, 'hr_zones_json' => '{"4": 900.0, "5": 300.0}']);
        $this->seedSession(31, 3, ['training_load' => 100.0, 'duration_s' => 3000.0, 'avg_hr' => 130, 'hr_zones_json' => '{"4": 600.0, "5": 0.0}']);
        $this->seedSession(32, 5, ['training_load' => 200.0, 'duration_s' => 4200.0, 'avg_hr' => 150, 'hr_zones_json' => '{"4": 1200.0, "5": 600.0}']);
        $this->seedSession(33, 7, ['training_load' => 120.0, 'avg_hr' => 135, 'hr_zones_json' => null]);
        // Another sport two days ago and a later session of the same
        // kind: neither is "earlier of the same kind".
        $this->seedSession(34, 2, ['type_key' => 'running', 'training_load' => 500.0]);
        $this->seedSession(35, 0, ['training_load' => 999.0]);

        $comparison = $this->payload(['activity_id' => 30])['comparison'];

        $this->assertTrue($comparison['has_data']);
        $this->assertSame([31, 32, 33], array_column($comparison['sessions'], 'id'));
        $this->assertEqualsWithDelta(120.0, $comparison['median']['training_load'], 0.05);
        $this->assertEqualsWithDelta(3600.0, $comparison['median']['duration_s'], 0.05);
        $this->assertEqualsWithDelta(135.0, $comparison['median']['avg_hr'], 0.05);
        // Only two of the three carry zones; the median ignores the gap
        // rather than reading it as zero.
        $this->assertEqualsWithDelta(20.0, $comparison['median']['hard_minutes'], 0.05);
        $this->assertEqualsWithDelta(30.0, $comparison['deviation']['training_load']['absolute'], 0.05);
        $this->assertEqualsWithDelta(25.0, $comparison['deviation']['training_load']['pct'], 0.05);
        $this->assertEqualsWithDelta(0.0, $comparison['deviation']['duration_s']['absolute'], 0.05);
        $this->assertEqualsWithDelta(5.0, $comparison['deviation']['avg_hr']['absolute'], 0.05);
        // 200 beats 150, nothing else does: second of four.
        $this->assertSame(['load_rank' => 2, 'of' => 4], $comparison['rank']);

        $narrow = $this->payload(['activity_id' => 30, 'compare_with' => 2])['comparison'];
        $this->assertSame([31, 32], array_column($narrow['sessions'], 'id'));
    }

    public function test_a_session_without_earlier_ones_of_its_kind_says_so(): void
    {
        $this->seedSession(36, 1, ['type_key' => 'pilates']);

        $comparison = $this->payload()['comparison'];

        $this->assertFalse($comparison['has_data']);
        $this->assertStringContainsString('pilates', $comparison['hint']);
    }

    public function test_it_buckets_the_heart_rate_curve(): void
    {
        $date = $this->seedSession(40, 1);

        // One sample per minute climbing from 100, plus one before the
        // session and one after it that must stay out of the curve.
        $samples = [];
        for ($minute = 0; $minute < 60; $minute++) {
            $samples[] = [
                'ts_local' => sprintf('%s 18:%02d:00', $date, $minute),
                'date' => $date,
                'heart_rate' => 100 + $minute,
            ];
        }
        $samples[] = ['ts_local' => $date.' 17:58:00', 'date' => $date, 'heart_rate' => 190];
        $samples[] = ['ts_local' => $date.' 19:05:00', 'date' => $date, 'heart_rate' => 191];
        $this->seedMirror('intraday', $samples);

        $curve = $this->payload()['hr_curve'];

        $this->assertTrue($curve['has_data']);
        $this->assertSame(60, $curve['samples']);
        // Sixty minutes into about thirty points: two minutes a bucket.
        $this->assertSame(2, $curve['bucket_minutes']);
        $this->assertCount(30, $curve['points']);
        $this->assertSame(['t_min' => 0, 'avg_hr' => 101, 'max_hr' => 101], $curve['points'][0]);
        $this->assertSame(['t_min' => 58, 'avg_hr' => 159, 'max_hr' => 159], $curve['points'][29]);
        $this->assertSame(159, $curve['peak_hr']);
        $this->assertSame(59, $curve['peak_at_min']);
    }

    public function test_the_curve_can_be_left_out(): void
    {
        $this->seedSession(41, 1);

        $payload = $this->payload(['include_hr_curve' => false]);

        $this->assertTrue($payload['has_data']);
        $this->assertArrayNotHasKey('hr_curve', $payload);
    }

    public function test_it_summarises_laps_and_sets(): void
    {
        $date = $this->seedSession(50, 1, ['type_key' => 'strength_training']);

        $this->seedMirror('activity_laps', [
            ['activity_id' => 50, 'lap_index' => 1, 'duration_s' => 600.0, 'moving_s' => 600.0, 'distance_m' => 1000.0, 'avg_hr' => 150],
            ['activity_id' => 50, 'lap_index' => 2, 'duration_s' => 240.0, 'moving_s' => 0.0, 'distance_m' => 0.0, 'avg_hr' => 160],
            ['activity_id' => 50, 'lap_index' => 3, 'duration_s' => 610.0, 'moving_s' => 605.0, 'distance_m' => 1000.0, 'avg_hr' => 155],
        ]);

        $this->seedMirror('strength_sets', [
            ['activity_id' => 50, 'set_index' => 0, 'exercise_category' => 'BENCH_PRESS', 'set_type' => 'ACTIVE', 'reps' => 10, 'weight_g' => 60000.0, 'duration_s' => 40.0, 'start_local' => $date.' 18:01:00'],
            ['activity_id' => 50, 'set_index' => 1, 'exercise_category' => null, 'set_type' => 'REST', 'reps' => null, 'weight_g' => null, 'duration_s' => 90.0, 'start_local' => $date.' 18:02:00'],
            ['activity_id' => 50, 'set_index' => 2, 'exercise_category' => 'BENCH_PRESS', 'set_type' => 'ACTIVE', 'reps' => 8, 'weight_g' => 70000.0, 'duration_s' => 35.0, 'start_local' => $date.' 18:04:00'],
            ['activity_id' => 50, 'set_index' => 3, 'exercise_category' => 'SQUAT', 'set_type' => 'ACTIVE', 'reps' => 12, 'weight_g' => null, 'duration_s' => 50.0, 'start_local' => $date.' 18:06:00'],
        ]);

        $payload = $this->payload();

        $this->assertSame(3, $payload['laps']['lap_count']);
        $this->assertSame([1, 2, 3], array_column($payload['laps']['laps'], 'lap'));
        $this->assertEqualsWithDelta(0.0, $payload['laps']['laps'][1]['distance_m'], 0.05);

        $sets = $payload['strength_sets'];
        $this->assertSame(3, $sets['active_sets']);
        $this->assertSame(1, $sets['rest_sets']);

        $bench = $sets['categories'][0];
        $this->assertSame('BENCH_PRESS', $bench['category']);
        $this->assertSame(2, $bench['sets']);
        $this->assertSame(18, $bench['reps']);
        $this->assertEqualsWithDelta(70.0, $bench['top_weight_kg'], 0.05);
        $this->assertEqualsWithDelta(75.0, $bench['duration_s'], 0.05);

        // A weightless category has no top weight rather than one of zero.
        $squat = $sets['categories'][1];
        $this->assertSame('SQUAT', $squat['category']);
        $this->assertArrayNotHasKey('top_weight_kg', $squat);
    }

    public function test_an_unknown_id_lists_candidates(): void
    {
        $this->seedSession(60, 2);
        $this->seedSession(61, 1);

        $payload = $this->payload(['activity_id' => 999]);

        $this->assertFalse($payload['has_data']);
        $this->assertStringContainsString('No activity with that id', $payload['hint']);
        $this->assertSame([61, 60], array_column($payload['candidates'], 'id'));
    }

    public function test_an_empty_mirror_says_so(): void
    {
        $payload = $this->payload();

        $this->assertFalse($payload['has_data']);
        $this->assertStringContainsString('no activities yet', $payload['hint']);
        $this->assertSame([], $payload['candidates']);
    }

    public function test_the_health_data_switch_closes_it(): void
    {
        $this->seedSession(70, 1);

        ConnectorSettings::for($this->athlete())->update(['share_health_data' => false]);

        GarminHealthServer::tool(GetActivityDetailTool::class)->assertHasErrors();
    }
}
