<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AthleteProfile;
use App\Models\PushSend;
use App\Models\PushSubscription;
use App\Push\Vapid;
use App\Push\WeeklyReminder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Sunday reminder for the weekly report: once per report week, a tap
 * target on claude.ai with the prepared prompt. The chat answer is the
 * report; nothing is saved, so the reminder simply lives out its Sunday.
 */
class WeeklyReminderTest extends TestCase
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

        // Sunday evening, the scheduled moment.
        Carbon::setTestNow('2026-08-02 19:00:00');

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

    private function decodedPrompt(string $url): string
    {
        $this->assertStringStartsWith('https://claude.ai/new?q=', $url);

        return rawurldecode(substr($url, strlen('https://claude.ai/new?q=')));
    }

    public function test_the_reminder_rings_once_per_report_week(): void
    {
        Http::fake();
        $this->subscribe();

        $this->artisan('app:weekly-report-reminder')->assertSuccessful();
        $this->artisan('app:weekly-report-reminder')->assertSuccessful();

        // A Monday catch-up run still means the week that ended on Sunday,
        // so the ledger has to block it too.
        Carbon::setTestNow('2026-08-03 10:00:00');
        $this->artisan('app:weekly-report-reminder')->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(PushSend::KIND_WEEKLY, PushSend::sole()->kind);
    }

    public function test_the_feed_serves_the_claude_url_with_the_prepared_prompt(): void
    {
        Http::fake();
        $this->subscribe();
        $this->artisan('app:weekly-report-reminder');

        $response = $this->actingAs($this->athlete())
            ->getJson('/push/next')
            ->assertOk()
            ->assertJsonPath('window.type', 'weekly-report')
            ->assertJsonPath('window.title', 'Weekly report');

        $prompt = $this->decodedPrompt($response->json('window.url'));

        // The current week Monday to this Sunday, against the one before:
        // the same resolution the MCP weekly-report prompt makes for
        // weeks_back 0 on a Sunday.
        $this->assertStringContainsString('2026-07-27 to 2026-08-02', $prompt);
        $this->assertStringContainsString('2026-07-20 to 2026-07-26', $prompt);
        $this->assertStringContainsString('on training load, sleep and HRV', $prompt);
        // The chat answer is the report: the prompt must not steer the
        // model towards any tool that writes something back.
        $this->assertStringContainsString('nothing needs saving', $prompt);
        $this->assertStringNotContainsString('save-insight', $prompt);
        // Sunday itself is still running when the reminder fires.
        $this->assertStringContainsString('thin Sunday', $prompt);
    }

    public function test_the_prompt_follows_the_dashboard_language(): void
    {
        AthleteProfile::for($this->athlete())->update(['locale' => 'de']);

        $item = app(WeeklyReminder::class)->compose($this->athlete());

        $this->assertSame('Wochenreport', $item['title']);
        $this->assertStringContainsString('Erstelle meinen Wochenreport', $this->decodedPrompt($item['url']));
        // The switch is scoped to the composition; the app keeps its locale.
        $this->assertSame('en', app()->getLocale());
    }

    public function test_nothing_is_sent_without_a_device(): void
    {
        Http::fake();

        $this->artisan('app:weekly-report-reminder')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, PushSend::count());
    }
}
