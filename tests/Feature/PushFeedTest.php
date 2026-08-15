<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HealthAlert;
use App\Models\PushSend;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The multi-typed push feed: one endpoint, five kinds, and always the
 * newest still-pending item. The shape under "window" is a compatibility
 * promise: title, body and url are what a service worker from before the
 * feed grew types keeps reading, and "type" only ever came on top.
 */
class PushFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete();

        Carbon::setTestNow('2026-07-29 15:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function alertAt(string $time, string $rule = 'readiness', string $message = 'Readiness 21: zone 1 only today.'): void
    {
        Carbon::setTestNow($time);
        HealthAlert::create(['user_id' => $this->athlete()->id, 'rule' => $rule, 'date' => now()->toDateString(), 'message' => $message]);
        Carbon::setTestNow('2026-07-29 15:00:00');
    }

    private function feed()
    {
        return $this->actingAs($this->athlete())->getJson('/push/next');
    }

    public function test_an_alert_keeps_the_old_shape_and_names_its_type(): void
    {
        $this->alertAt('2026-07-29 14:45:00');

        $this->feed()
            ->assertOk()
            ->assertJsonPath('window.type', 'health-alert')
            ->assertJsonPath('window.title', 'Health alert')
            ->assertJsonPath('window.body', 'Readiness 21: zone 1 only today.')
            ->assertJsonPath('window.url', route('dashboard'));
    }

    public function test_todays_alerts_join_into_one_notification(): void
    {
        $this->alertAt('2026-07-29 09:45:00');
        $this->alertAt('2026-07-29 09:45:30', 'hrv', 'HRV below the normal band for 3 days.');

        $response = $this->feed();

        $response->assertJsonPath('window.title', '2 health alerts');
        $this->assertStringContainsString('Readiness 21', $response->json('window.body'));
        $this->assertStringContainsString('HRV below the normal band', $response->json('window.body'));
    }

    public function test_yesterdays_alert_is_no_longer_served(): void
    {
        $this->alertAt('2026-07-28 09:45:00');

        $this->feed()->assertJsonPath('window', null);
    }

    public function test_a_briefing_from_yesterday_never_reappears(): void
    {
        // Freshness is per kind: the briefing lives for its own day only.
        PushSend::create([
            'user_id' => $this->athlete()->id,
            'kind' => PushSend::KIND_BRIEFING,
            'date' => now()->subDay()->toDateString(),
            'sent_at' => Carbon::parse('2026-07-28 09:40:00'),
            'devices' => 1,
        ]);

        $this->feed()->assertJsonPath('window', null);
    }
}
