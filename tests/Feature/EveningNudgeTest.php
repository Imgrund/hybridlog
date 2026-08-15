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
 * The conditional evening nudge: it speaks on a drifting bedtime, and an
 * evening without one sends nothing at all. The feed re-asks the
 * condition when the notification is shown.
 */
class EveningNudgeTest extends TestCase
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

        // After the evening fetch.
        Carbon::setTestNow('2026-07-30 21:15:00');

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
     * @param  bool  $steadyBedtime  false alternates 22:00 and 23:30 nights
     */
    private function mockMirror(bool $steadyBedtime = true): void
    {
        $sleep = collect(range(0, 13))->map(function (int $i) use ($steadyBedtime) {
            $bedtime = $steadyBedtime || $i % 2 === 0 ? [22, 0] : [23, 30];

            return (object) [
                'date' => now()->subDays($i)->toDateString(),
                'score' => 78,
                'duration_s' => 7 * 3600,
                'start_local' => now()->subDays($i + 1)->setTime(...$bedtime)->format('Y-m-d H:i:s'),
                'end_local' => now()->subDays($i)->setTime(6, 30)->format('Y-m-d H:i:s'),
            ];
        })->reverse()->values();

        $this->mock(GarminData::class, function ($mock) use ($sleep) {
            $mock->shouldReceive('activities')->andReturn(new Collection);
            $mock->shouldReceive('sleep')->andReturn($sleep);
        });
    }

    public function test_a_drifting_bedtime_nudges_once(): void
    {
        Http::fake();
        $this->subscribe();
        $this->mockMirror(steadyBedtime: false);

        $this->artisan('app:evening-nudge')->assertSuccessful();
        $this->artisan('app:evening-nudge')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(PushSend::KIND_NUDGE, PushSend::sole()->kind);
    }

    public function test_a_drifting_bedtime_names_the_median(): void
    {
        // Fourteen nights alternating 22:00 and 23:30: a sample standard
        // deviation of 47 min, median 22:45.
        Http::fake();
        $this->subscribe();
        $this->mockMirror(steadyBedtime: false);

        $this->artisan('app:evening-nudge')->assertSuccessful();

        Http::assertSentCount(1);

        $this->actingAs($this->athlete())
            ->getJson('/push/next')
            ->assertOk()
            ->assertJsonPath('window.type', 'evening-nudge')
            ->assertJsonPath('window.url', route('dashboard'))
            ->assertJsonPath('window.body', 'Bedtime has drifted by ±47 min. In bed before 22:45 holds your window (median of the last 14 nights).');
    }

    public function test_a_quiet_evening_sends_nothing(): void
    {
        Http::fake();
        $this->subscribe();
        $this->mockMirror();

        $this->artisan('app:evening-nudge')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, PushSend::count());
    }
}
