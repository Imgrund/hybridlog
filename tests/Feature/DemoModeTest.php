<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Garmin\FetchTrigger;
use App\Garmin\GarminLogin;
use App\Jobs\RunGarminFetch;
use App\Jobs\RunGarminLogin;
use App\Mcp\Servers\GarminHealthServer;
use App\Mcp\Tools\LogSymptomTool;
use App\Mcp\Tools\RefreshDataTool;
use App\Models\GarminLoginAttempt;
use App\Push\Vapid;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What a public demo may and may not do.
 *
 * The demo is one account a hundred strangers sign in to, so every
 * surface that would reach out of the installation on that account's
 * behalf is closed: a Garmin password, a token for a chat client, a
 * phone subscription, a fetch. Reading stays open, and so do the two
 * things a visitor is meant to try.
 *
 * The point of testing it is that the switch is a security boundary now.
 * A route added to the wrong group, or a queue job that keeps its own
 * way in, would be a stranger's Garmin credentials on somebody else's
 * server, and nothing else in the suite would notice.
 */
class DemoModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['demo.enabled' => true]);
    }

    /**
     * Every door the demo keeps shut, by the address a visitor reaches it
     * at. Written out rather than derived from the route file, so that
     * moving a route out of the guarded group fails here instead of
     * silently agreeing with itself.
     *
     * @return array<string, array{string, string}>
     */
    public static function closedDoors(): array
    {
        return [
            'the Garmin sign-in page' => ['get', '/connect/garmin'],
            'the Garmin sign-in itself' => ['post', '/connect/garmin'],
            'the MFA code' => ['post', '/connect/garmin/code'],
            'the sign-in status' => ['get', '/connect/garmin/status'],
            'the AI connector' => ['get', '/connect'],
            'the connector permissions' => ['post', '/connect/permissions'],
            'the connector disconnect' => ['post', '/connect/disconnect'],
            'the manual fetch' => ['post', '/fetch'],
            'the notification settings' => ['get', '/connect/notifications'],
            'subscribing a device' => ['post', '/push/subscribe'],
            'unsubscribing a device' => ['post', '/push/unsubscribe'],
            'the notification feed' => ['get', '/push/next'],
        ];
    }

    #[DataProvider('closedDoors')]
    public function test_every_closed_door_is_shut(string $method, string $uri): void
    {
        Queue::fake();
        Process::fake();

        $this->actingAs($this->athlete())->$method($uri)->assertForbidden();

        Queue::assertNothingPushed();
        Process::assertNothingRan();
    }

    public function test_a_closed_door_says_what_this_installation_is(): void
    {
        // Not a raw 403 page: the visitor did nothing wrong, and the
        // reason they cannot connect their watch here is worth a page.
        $this->actingAs($this->athlete())
            ->get('/connect/garmin')
            ->assertForbidden()
            ->assertSee('Not part of the demo')
            ->assertSee('Everybody who visits this dashboard signs in to the same account', false)
            // Above all, no password field: the whole point of closing
            // this page is that nobody types their Garmin password in.
            ->assertDontSee('current-password');
    }

    public function test_a_machine_gets_the_same_answer_as_json(): void
    {
        // The service worker and the OAuth clients that reach these
        // routes cannot read a page, and a JSON caller handed HTML
        // reports something other than what happened.
        $this->actingAs($this->athlete())
            ->postJson('/push/subscribe', ['endpoint' => 'https://push.example.com/x'])
            ->assertForbidden()
            ->assertJsonFragment(['error' => __('This is the public demo, where everybody signs in to the same account, so anything that connects an account, sends to a device or fetches from Garmin is switched off here.')]);
    }

    public function test_a_chat_client_cannot_register_itself(): void
    {
        // Dynamic registration is what turns a stranger's client into a
        // client of this installation; the token it would then be granted
        // is a token to the account everybody shares.
        $this->postJson('/oauth/register', [
            'client_name' => 'somebody',
            'redirect_uris' => ['https://example.com/callback'],
        ])->assertForbidden();

        $this->assertDatabaseCount('oauth_clients', 0);
    }

    public function test_the_mcp_endpoint_is_closed_too(): void
    {
        // Nothing can reach it once no client can register, but a token
        // minted before the switch was thrown would otherwise keep
        // reading a mirror that is now shared.
        $this->postJson('/mcp/garmin', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            ->assertForbidden();
    }

    public function test_the_login_job_refuses_and_leaves_nothing_waiting(): void
    {
        // The queue is the last way into Garmin once the page is closed.
        $user = $this->athlete();
        $attempt = GarminLoginAttempt::create(['user_id' => $user->id, 'status' => GarminLoginAttempt::STARTING]);

        Process::fake();
        (new RunGarminLogin($attempt->id, 'someone@example.com', 'a password', $user->id))->handle(app(GarminLogin::class));

        Process::assertNothingRan();
        $this->assertSame(GarminLoginAttempt::FAILED, $attempt->refresh()->status);
        $this->assertStringContainsString('public demo', (string) $attempt->error);
    }

    public function test_the_fetch_job_refuses_and_clears_its_running_mark(): void
    {
        Process::fake();
        $user = $this->athlete();

        (new RunGarminFetch($user->id))->handle();

        Process::assertNothingRan();
        $this->assertFalse(app(FetchTrigger::class)->isRunning($user->id));
    }

    public function test_the_trigger_turns_a_fetch_away_with_a_reason(): void
    {
        Queue::fake();

        $reason = app(FetchTrigger::class)->start($this->athlete()->id);

        $this->assertNotNull($reason);
        Queue::assertNothingPushed();
    }

    public function test_the_refresh_tool_answers_instead_of_starting_a_run(): void
    {
        // Cleanly refused rather than dispatched into a queue job that
        // fails: the model gets a reason it can say out loud.
        Queue::fake();
        $this->athlete();

        $response = GarminHealthServer::tool(RefreshDataTool::class, []);

        $response->assertOk()->assertSee('"started":false');
        $response->assertSee('public demo');
        Queue::assertNothingPushed();
    }

    public function test_push_is_switched_off_at_the_signer(): void
    {
        // Even with a key pair in the environment. Everybody there is
        // signed in to the same account, so a subscription is a
        // stranger's device on a shared ledger.
        config([
            'push.vapid.public_key' => 'BFakePublicKey',
            'push.vapid.private_key' => 'FakePrivateKey',
        ]);

        $this->assertFalse(app(Vapid::class)->configured());
    }

    public function test_reading_and_the_two_things_a_visitor_may_try_stay_open(): void
    {
        $user = $this->athlete();

        $this->actingAs($user)->get('/')->assertStatus(200);
        $this->actingAs($user)->get('/profile')->assertStatus(200);
        // The language is not somebody else's to lose, and the nightly
        // reset puts it back anyway.
        $this->actingAs($user)->post('/profile/language', ['locale' => 'de'])
            ->assertRedirect(route('profile'));
        $this->actingAs($user)->get('/fetch/status')->assertStatus(200);
    }

    public function test_the_symptom_log_is_still_part_of_the_demo(): void
    {
        // It is half of what there is to try here: a niggle named in the
        // chat lands on the body map. Wiped overnight with the rest.
        $this->athlete();

        GarminHealthServer::tool(LogSymptomTool::class, [
            'symptom' => 'scratchy throat',
            'severity' => 2,
        ])->assertOk();

        $this->assertDatabaseCount('symptom_log', 1);
    }

    public function test_the_header_says_this_is_a_public_demo(): void
    {
        // Quietly, in the line the Garmin warnings would have used: on a
        // demo those all say something untrue, and by the evening the
        // seed's own timestamp reads as a fetch that stopped running.
        $this->actingAs($this->athlete())
            ->get('/')
            ->assertSee('Public demo')
            ->assertSee('A public demo on generated data', false)
            // The one real action in that header cannot work here, so it
            // is not offered: a button that only ever explains itself is
            // worse than no button.
            ->assertDontSee('Fetch from Garmin');
    }

    public function test_the_menu_offers_no_connection_a_visitor_cannot_make(): void
    {
        $this->actingAs($this->athlete())
            ->get('/')
            ->assertDontSee(route('connect.garmin'))
            ->assertDontSee(route('connect.notifications'))
            // What is left is what still works.
            ->assertSee(route('profile'));
    }

    public function test_nothing_is_closed_on_a_normal_installation(): void
    {
        // The guard is a pass-through when the switch is off, which is
        // what lets it sit on the routes unconditionally.
        config(['demo.enabled' => false]);

        $this->actingAs($this->athlete())->get('/connect/garmin')->assertStatus(200);
        $this->actingAs($this->athlete())->get('/connect')->assertStatus(200);
    }
}
