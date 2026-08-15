<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\View\Heartbeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesGarminMirror;
use Tests\TestCase;

/**
 * The drawn heart: the organ inside the solid body map animates the same
 * quantity the HRV card prints, so the two must not be able to disagree.
 * The swing has to grow with the value, the beat has to sit on the
 * athlete's own resting pulse, and a mirror with nothing to read must
 * not beat at all.
 *
 * The animation itself lives in a WebGL scene; what is tested here is
 * the contract it draws from, which is the Heartbeat value object and the
 * data attributes the stage carries.
 */
class HrvHeartTest extends TestCase
{
    use FakesGarminMirror;
    use RefreshDatabase;

    /** @return array{interval: float, sway: float}|null */
    private function heart(?float $hrv, ?float $low = 45.0, ?float $high = 65.0, ?float $restingHr = 50.0): ?array
    {
        $beat = Heartbeat::from($hrv, $low, $high, $restingHr);

        return $beat === null ? null : ['interval' => (float) $beat->interval, 'sway' => $beat->sway];
    }

    public function test_the_swing_grows_with_the_value(): void
    {
        // Floor, middle and ceiling of the same band. Only the order is
        // asserted, not the numbers: the mapping is a display decision and
        // may be retuned, but a worse reading must never beat livelier.
        $low = $this->heart(45.0);
        $mid = $this->heart(55.0);
        $high = $this->heart(65.0);

        $this->assertGreaterThan($low['sway'], $mid['sway']);
        $this->assertGreaterThan($mid['sway'], $high['sway']);
        $this->assertGreaterThan(0.0, $low['sway'], 'A low reading still beats, it just beats evenly.');
    }

    public function test_the_swing_stops_at_the_edges_of_the_band(): void
    {
        // Outside the band the status word takes over. The animation holds
        // where it is rather than running away, in either direction.
        $this->assertSame($this->heart(45.0)['sway'], $this->heart(20.0)['sway']);
        $this->assertSame($this->heart(65.0)['sway'], $this->heart(140.0)['sway']);
    }

    public function test_the_beat_sits_on_the_resting_pulse(): void
    {
        // 40 bpm is a beat every 1500 ms, 60 bpm one every 1000.
        $this->assertSame(1500.0, $this->heart(55.0, restingHr: 40.0)['interval']);
        $this->assertSame(1000.0, $this->heart(55.0, restingHr: null)['interval'], 'No pulse on record falls back to 60.');
        $this->assertSame(1000.0, $this->heart(55.0, restingHr: 0.0)['interval'], 'A nonsense pulse falls back too.');
    }

    public function test_a_mirror_with_nothing_to_read_yields_no_beat(): void
    {
        $this->assertNull($this->heart(null), 'No value, no heart.');
        $this->assertNull($this->heart(55.0, low: null), 'No band to read the value against, no heart.');
        $this->assertNull($this->heart(55.0, low: 60.0, high: 60.0), 'A band of zero width would divide by nothing.');
    }

    public function test_the_body_map_beats_to_the_resting_pulse(): void
    {
        // The organ in the solid draws the same measurement the HRV card
        // prints, off the same Heartbeat resolution in SurfacePage, so a
        // difference between the two numbers is the bug this test is for.
        $user = User::factory()->create();
        $days = collect(range(1, 31))->map(fn (int $i) => $this->dayRow($i, 50.0))
            ->push($this->dayRow(0, 40.0))->values();
        $this->mockGarmin(
            ['rhrToday' => 40.0, 'respToday' => 14.0, 'hrvLastNight' => 55.0],
            days: $days,
        );

        $html = $this->actingAs($user)->get('/')->assertStatus(200)->getContent();

        $this->assertMatchesRegularExpression(
            '/bm-stage.*?data-beat-interval="1500"/s',
            $html,
            'The solid beats at the resting pulse the mirror holds, not at a default.',
        );
        $this->assertMatchesRegularExpression('/bm-stage.*?data-beat-sway="[\d.]+"/s', $html);
    }

    public function test_a_body_map_with_nothing_to_read_does_not_beat(): void
    {
        // No HRV baseline means no band, and an animation running on a
        // missing measurement invents one. The stage still renders, it
        // just carries no beat.
        $user = User::factory()->create();
        $this->mockGarmin(
            ['rhrToday' => 40.0, 'respToday' => 14.0, 'hrvLastNight' => 55.0],
            hrv: collect(),
        );

        $html = $this->actingAs($user)->get('/')->assertStatus(200)->getContent();

        $this->assertStringContainsString('bm-stage', $html);
        $this->assertStringNotContainsString('data-beat-interval', $html);
    }
}
