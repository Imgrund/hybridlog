<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Garmin\GarminData;
use App\Models\PushSend;
use App\Models\PushSubscription;
use App\Push\Vapid;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The morning briefing: once per day, silent without today's data, and
 * worded from the same reading the hero makes. Data comes from a mocked
 * GarminData so the verdict inputs are exercised deterministically.
 */
class MorningBriefingTest extends TestCase
{
    use RefreshDatabase;

    private static array $keys;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$keys = Vapid::generate();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete();

        Carbon::setTestNow('2026-07-30 09:40:00');

        config([
            'push.vapid.public_key' => self::$keys['public'],
            'push.vapid.private_key' => self::$keys['private'],
            'push.vapid.subject' => 'mailto:athlete@example.com',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function subscribe(): void
    {
        PushSubscription::remember('https://fcm.googleapis.com/fcm/send/device-1', 'iPhone', $this->athlete());
    }

    /**
     * Quiet baseline: readiness fine, HRV in band, ACWR in the corridor,
     * no illness pattern. Single aspects are steered per test.
     */
    private function mockMirror(array $overrides = []): void
    {
        $days = collect(range(0, 29))->map(fn (int $i) => (object) [
            'date' => now()->subDays($i)->toDateString(),
            'resting_hr' => 50.0,
            'min_hr' => 42,
            'max_hr' => 150,
            'vo2max_running' => null,
            'calories_active' => 700,
            'stress_avg' => 30,
            'stress_max' => 70,
            'bb_low' => 30,
            'bb_high' => 90,
            'spo2_avg' => null,
        ])->reverse()->values();

        $sleep = collect(range(0, 29))->map(fn (int $i) => (object) [
            'date' => now()->subDays($i)->toDateString(),
            'score' => 78,
            'duration_s' => 7 * 3600,
            'start_local' => now()->subDays($i + 1)->setTime(22, 45)->format('Y-m-d H:i:s'),
            'end_local' => now()->subDays($i)->setTime(6, 30)->format('Y-m-d H:i:s'),
            'deep_s' => 5400,
            'rem_s' => 5900,
            'light_s' => 12000,
            'respiration_avg' => 14.0,
            'respiration_lowest' => 12.0,
            'respiration_highest' => 17.0,
        ])->reverse()->values();

        $hrv = collect(range(0, 9))->map(fn (int $i) => (object) [
            'date' => now()->subDays($i)->toDateString(),
            'weekly_avg' => 55.0,
            'last_night_avg' => 55.0,
            'baseline_balanced_low' => 45.0,
            'baseline_balanced_upper' => 65.0,
            'status' => 'BALANCED',
        ])->reverse()->values();

        $defaults = [
            'readiness' => collect([(object) [
                'date' => now()->toDateString(),
                'score' => 82,
                'current_score' => null,
                'recovery_time_h' => 6,
                'current_recovery_time_h' => null,
                'current_at' => null,
            ]]),
            'days' => $days,
            'sleep' => $sleep,
            'hrv' => $hrv,
            'trainingStatus' => collect([(object) [
                'date' => now()->toDateString(),
                'acwr' => 1.0,
                'acute_load' => 300.0,
                'chronic_load' => 300.0,
            ]]),
            'activities' => new Collection,
            'bodyComp' => new Collection,
            'strengthSets' => new Collection,
        ];
        $data = array_merge($defaults, $overrides);

        $this->mock(GarminData::class, function ($mock) use ($data) {
            foreach (['readiness', 'days', 'sleep', 'hrv', 'trainingStatus', 'activities', 'bodyComp', 'strengthSets'] as $method) {
                $mock->shouldReceive($method)->andReturn($data[$method]);
            }
            $mock->shouldReceive('heartProfile')->andReturnNull();
        });
    }

    /* ------------------------------------------------------- the command */

    public function test_a_morning_with_data_sends_the_briefing_once(): void
    {
        Http::fake();
        $this->subscribe();
        $this->mockMirror();

        $this->artisan('app:morning-briefing')->assertSuccessful();
        $this->artisan('app:morning-briefing')->assertSuccessful();

        Http::assertSentCount(1);
        $sent = PushSend::sole();
        $this->assertSame(PushSend::KIND_BRIEFING, $sent->kind);
        $this->assertSame(1, $sent->devices);
    }

    public function test_a_morning_without_todays_data_stays_silent(): void
    {
        // Honesty before cadence: the fetch brought nothing for today, so
        // there is no briefing rather than one about yesterday.
        Http::fake();
        $this->subscribe();
        $this->mockMirror(['readiness' => collect([(object) [
            'date' => now()->subDay()->toDateString(),
            'score' => 82,
            'current_score' => null,
            'recovery_time_h' => 6,
            'current_recovery_time_h' => null,
            'current_at' => null,
        ]])]);

        $this->artisan('app:morning-briefing')
            ->expectsOutputToContain('staying silent')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, PushSend::count());
    }

    public function test_nothing_is_sent_without_a_device(): void
    {
        Http::fake();

        $this->artisan('app:morning-briefing')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, PushSend::count());
    }

    /* ------------------------------------------------- what the feed says */

    private function sendBriefing(): void
    {
        Http::fake();
        $this->subscribe();
        $this->artisan('app:morning-briefing');
    }

    public function test_the_feed_carries_verdict_and_readiness(): void
    {
        $this->mockMirror();
        $this->sendBriefing();

        $this->actingAs($this->athlete())
            ->getJson('/push/next')
            ->assertOk()
            ->assertJsonPath('window.type', 'morning-briefing')
            ->assertJsonPath('window.title', 'Ready, readiness 82')
            ->assertJsonPath('window.url', route('dashboard'))
            ->assertJsonPath('window.body', '');
    }

    public function test_open_recovery_time_is_the_focus(): void
    {
        $this->mockMirror(['readiness' => collect([(object) [
            'date' => now()->toDateString(),
            'score' => 82,
            'current_score' => null,
            'recovery_time_h' => 26,
            'current_recovery_time_h' => null,
            'current_at' => null,
        ]])]);
        $this->sendBriefing();

        $response = $this->actingAs($this->athlete())->getJson('/push/next');

        $this->assertStringContainsString('26 h recovery time still open.', $response->json('window.body'));
    }

    public function test_the_most_loaded_zone_is_the_focus(): void
    {
        // A heavy squat session last evening leaves the legs well below
        // fresh by this morning, so the briefing names them. The zone label
        // itself belongs to MuscleFreshness's tests, not this one.
        // Seeded against the real clock, not Carbon's frozen test now:
        // MuscleFreshness decays load against time(), like its own tests.
        $evening = Carbon::parse(date('Y-m-d', strtotime('-1 day')).' 18:00:00');
        $this->mockMirror(['strengthSets' => collect(range(1, 6))->map(fn (int $i) => (object) [
            'activity_id' => 900,
            'set_index' => $i,
            'exercise_name' => null,
            'exercise_category' => 'SQUAT',
            'set_type' => 'ACTIVE',
            'reps' => 5,
            'weight_g' => 80_000.0,
            'duration_s' => 45.0,
            'start_local' => $evening->copy()->addMinutes($i)->format('Y-m-d H:i:s'),
        ])]);
        $this->sendBriefing();

        $response = $this->actingAs($this->athlete())->getJson('/push/next');

        $this->assertStringContainsString('Most loaded:', $response->json('window.body'));
    }
}
