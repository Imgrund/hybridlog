<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Garmin\GarminData;
use App\Models\HealthAlert;
use App\Models\PushSubscription;
use App\Push\Vapid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * app:health-alerts has exactly three hard rules and a one-per-rule-and-day
 * dedupe. Data comes from a mocked GarminData, so the thresholds are
 * exercised deterministically instead of depending on the live mirror.
 */
class HealthAlertsTest extends TestCase
{
    use RefreshDatabase;

    private const NOTIFIER = '/opt/hybridlog/notify-test';

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

        // Delivery also goes through whatever GARMIN_ALERT_COMMAND names,
        // so the tests configure a notifier of their own instead of
        // depending on one being installed on the machine running the
        // suite. Web push stays off by default here: no keys and no
        // subscription until a test brings its own.
        config(['garmin.alert_command' => self::NOTIFIER]);
    }

    private function subscribeDevice(): void
    {
        config([
            'push.vapid.public_key' => self::$keys['public'],
            'push.vapid.private_key' => self::$keys['private'],
            'push.vapid.subject' => 'mailto:athlete@example.com',
        ]);
        PushSubscription::remember('https://fcm.googleapis.com/fcm/send/device-1', 'iPhone', $this->athlete());
    }

    /**
     * Quiet baseline: readiness fine, HRV inside the band, ACWR in the
     * corridor. Single rules are broken per test via $overrides.
     */
    private function mockGarmin(array $overrides = []): void
    {
        $hrvQuiet = collect(range(0, 9))->map(fn (int $i) => (object) [
            'date' => now()->subDays($i)->toDateString(),
            'weekly_avg' => 55.0,
            'baseline_balanced_low' => 45.0,
            'baseline_balanced_upper' => 65.0,
        ])->reverse()->values();

        $defaults = [
            'readiness' => collect([(object) [
                'date' => now()->toDateString(),
                'score' => 80,
                'current_score' => null,
            ]]),
            'hrv' => $hrvQuiet,
            'trainingStatus' => collect([(object) [
                'date' => now()->toDateString(),
                'acwr' => 1.0,
                'acute_load' => 300.0,
                'chronic_load' => 300.0,
            ]]),
            'activities' => collect(),
            // The drift pass reads these three too; empty keeps every
            // slow rule under its minimum footing and therefore silent,
            // which is exactly the quiet baseline these tests assume.
            'days' => collect(),
            'sleep' => collect(),
            'bodyComp' => collect(),
        ];
        $data = array_merge($defaults, $overrides);

        $this->mock(GarminData::class, function ($mock) use ($data) {
            $mock->shouldReceive('readiness')->andReturn($data['readiness']);
            $mock->shouldReceive('hrv')->andReturn($data['hrv']);
            $mock->shouldReceive('trainingStatus')->andReturn($data['trainingStatus']);
            $mock->shouldReceive('activities')->andReturn($data['activities']);
            $mock->shouldReceive('days')->andReturn($data['days']);
            $mock->shouldReceive('sleep')->andReturn($data['sleep']);
            $mock->shouldReceive('bodyComp')->andReturn($data['bodyComp']);
        });
    }

    private function assertNotifiedOnce(string $needle): void
    {
        Process::assertRanTimes(function ($process) use ($needle) {
            $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

            return str_contains($command, self::NOTIFIER)
                && str_contains($command, $needle);
        }, 1);
    }

    public function test_a_readiness_under_25_notifies_and_writes_the_ledger(): void
    {
        Process::fake();
        $this->mockGarmin(['readiness' => collect([(object) [
            'date' => now()->toDateString(),
            'score' => 62,
            'current_score' => 21,
        ]])]);

        $this->artisan('app:health-alerts')->assertExitCode(0);

        $this->assertNotifiedOnce('Readiness 21');
        $this->assertDatabaseHas('health_alerts', [
            'rule' => 'readiness',
            'date' => now()->toDateString(),
        ]);
    }

    public function test_a_second_run_on_the_same_day_stays_silent(): void
    {
        Process::fake();
        $this->mockGarmin(['readiness' => collect([(object) [
            'date' => now()->toDateString(),
            'score' => 21,
            'current_score' => null,
        ]])]);

        $this->artisan('app:health-alerts')->assertExitCode(0);
        $this->artisan('app:health-alerts')->assertExitCode(0);

        $this->assertNotifiedOnce('Readiness 21');
        $this->assertSame(1, HealthAlert::count());
    }

    public function test_three_consecutive_hrv_days_under_the_band_notify(): void
    {
        Process::fake();
        $hrv = collect([3, 2, 1, 0])->map(fn (int $i) => (object) [
            'date' => now()->subDays($i)->toDateString(),
            'weekly_avg' => $i === 3 ? 50.0 : 38.0,
            'baseline_balanced_low' => 45.0,
            'baseline_balanced_upper' => 65.0,
        ])->values();
        $this->mockGarmin(['hrv' => $hrv]);

        $this->artisan('app:health-alerts')->assertExitCode(0);

        $this->assertNotifiedOnce('HRV below the normal band for 3 days');
        $this->assertDatabaseHas('health_alerts', ['rule' => 'hrv']);
    }

    public function test_two_underband_days_with_a_gap_do_not_count_as_three(): void
    {
        Process::fake();
        // Under the band today, yesterday and four days ago: the missing
        // nights in between break the streak.
        $hrv = collect([4, 1, 0])->map(fn (int $i) => (object) [
            'date' => now()->subDays($i)->toDateString(),
            'weekly_avg' => 38.0,
            'baseline_balanced_low' => 45.0,
            'baseline_balanced_upper' => 65.0,
        ])->values();
        $this->mockGarmin(['hrv' => $hrv]);

        $this->artisan('app:health-alerts')->assertExitCode(0);

        Process::assertNothingRan();
        $this->assertSame(0, HealthAlert::count());
    }

    public function test_a_critical_acwr_notifies(): void
    {
        Process::fake();
        $this->mockGarmin(['trainingStatus' => collect([(object) [
            'date' => now()->toDateString(),
            'acwr' => 1.62,
            'acute_load' => 800.0,
            'chronic_load' => 500.0,
        ]])]);

        $this->artisan('app:health-alerts')->assertExitCode(0);

        $this->assertNotifiedOnce('Load ratio 1.62');
        $this->assertDatabaseHas('health_alerts', ['rule' => 'acwr']);
    }

    public function test_without_a_broken_threshold_nothing_runs(): void
    {
        Process::fake();
        $this->mockGarmin();

        $this->artisan('app:health-alerts')->assertExitCode(0);

        Process::assertNothingRan();
        $this->assertSame(0, HealthAlert::count());
    }

    public function test_an_installation_without_a_notifier_reports_the_alert_instead_of_delivering_it(): void
    {
        config(['garmin.alert_command' => null]);
        Process::fake();
        $this->mockGarmin(['readiness' => collect([(object) [
            'date' => now()->toDateString(),
            'score' => 21,
            'current_score' => null,
        ]])]);

        $this->artisan('app:health-alerts')
            ->expectsOutputToContain('no GARMIN_ALERT_COMMAND set')
            ->assertExitCode(0);

        Process::assertNothingRan();
        // The ledger records deliveries, and nothing was delivered, so a
        // notifier configured later still gets to send today's alert.
        $this->assertSame(0, HealthAlert::count());
    }

    public function test_an_alert_also_rings_the_subscribed_devices(): void
    {
        // Web push comes on top of the shell notifier, not instead of it:
        // one wake for the run, and the command still gets its message.
        Http::fake();
        Process::fake();
        $this->subscribeDevice();
        $this->mockGarmin(['readiness' => collect([(object) [
            'date' => now()->toDateString(),
            'score' => 21,
            'current_score' => null,
        ]])]);

        $this->artisan('app:health-alerts')->assertExitCode(0);

        Http::assertSentCount(1);
        $this->assertNotifiedOnce('Readiness 21');
        $this->assertDatabaseHas('health_alerts', ['rule' => 'readiness']);
    }

    public function test_a_woken_device_alone_counts_as_delivery(): void
    {
        // The state item 2 of the roadmap fixed: GARMIN_ALERT_COMMAND was
        // never set in operation, so the rules fired into the void. With a
        // subscribed device the push is the delivery and the ledger fills.
        config(['garmin.alert_command' => null]);
        Http::fake();
        Process::fake();
        $this->subscribeDevice();
        $this->mockGarmin(['readiness' => collect([(object) [
            'date' => now()->toDateString(),
            'score' => 21,
            'current_score' => null,
        ]])]);

        $this->artisan('app:health-alerts')->assertExitCode(0);

        Http::assertSentCount(1);
        Process::assertNothingRan();
        $this->assertDatabaseHas('health_alerts', ['rule' => 'readiness']);
    }

    public function test_two_broken_rules_wake_the_devices_once(): void
    {
        Http::fake();
        Process::fake();
        $this->subscribeDevice();
        $hrv = collect([2, 1, 0])->map(fn (int $i) => (object) [
            'date' => now()->subDays($i)->toDateString(),
            'weekly_avg' => 38.0,
            'baseline_balanced_low' => 45.0,
            'baseline_balanced_upper' => 65.0,
        ])->values();
        $this->mockGarmin([
            'readiness' => collect([(object) [
                'date' => now()->toDateString(),
                'score' => 21,
                'current_score' => null,
            ]]),
            'hrv' => $hrv,
        ]);

        $this->artisan('app:health-alerts')->assertExitCode(0);

        // One buzz for the run; the feed joins the two messages anyway.
        Http::assertSentCount(1);
        $this->assertSame(2, HealthAlert::count());
    }
}
