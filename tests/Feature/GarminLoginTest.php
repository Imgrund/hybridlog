<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Garmin\FetchTrigger;
use App\Garmin\GarminLogin;
use App\Jobs\RunGarminLogin;
use App\Mcp\Servers\GarminHealthServer;
use App\Mcp\Tools\RefreshDataTool;
use App\Models\GarminLoginAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Signing in to Garmin from the dashboard instead of from a shell.
 *
 * The page is a state machine driven by one row: type the password, wait,
 * type the code Garmin sends, read the verdict. What is checked here is
 * that each stage shows its own thing and nothing else, that the code
 * only counts while a sign-in is actually waiting for one, and that the
 * password never comes to rest anywhere.
 */
class GarminLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->athlete();

        RateLimiter::clear('garmin-login');
    }

    private function attempt(string $status, array $extra = []): GarminLoginAttempt
    {
        return GarminLoginAttempt::create(
            ['user_id' => $this->athlete()->id, 'status' => $status] + $extra
        );
    }

    /** A mirror that has fetched successfully at some point. */
    private function seedWorkingFetch(): void
    {
        $this->seedMirror('fetch_log', [[
            'date' => now()->subDay()->toDateString(),
            'kind' => 'daily',
            'ok' => 1,
            'error' => null,
            'fetched_at' => now()->subHours(2)->format('Y-m-d\TH:i:s'),
        ]]);
    }

    /** A mirror whose stored Garmin session has since stopped working. */
    private function seedBrokenLogin(): void
    {
        $this->seedWorkingFetch();
        $this->seedMirror('fetch_log', [[
            'date' => now()->toDateString(),
            'kind' => 'login',
            'ok' => 0,
            'error' => 'GarminConnectAuthenticationError: 401',
            'fetched_at' => now()->format('Y-m-d\TH:i:s'),
        ]]);
    }

    public function test_the_page_requires_a_login(): void
    {
        $this->get('/connect/garmin')->assertRedirect(route('login'));
        $this->post('/connect/garmin')->assertRedirect(route('login'));
        $this->post('/connect/garmin/code')->assertRedirect(route('login'));
        $this->get('/connect/garmin/status')->assertRedirect(route('login'));
    }

    public function test_the_page_offers_the_form_and_says_what_happens_to_the_password(): void
    {
        $this->actingAs($this->athlete())
            ->get('/connect/garmin')
            ->assertStatus(200)
            ->assertSee('Sign in to Garmin Connect')
            ->assertSee('name="password"', false)
            ->assertSee('never written to the database', false);
    }

    public function test_submitting_the_form_hands_the_sign_in_to_a_worker(): void
    {
        // The password travels in the job payload and nowhere else: the
        // login has to outlive the request, because Garmin only asks for
        // the MFA code after the password has been accepted.
        Queue::fake();

        $this->actingAs($this->athlete())
            ->post('/connect/garmin', ['email' => 'athlete@example.com', 'password' => 'secret'])
            ->assertRedirect(route('connect.garmin'));

        Queue::assertPushed(RunGarminLogin::class, fn ($job) => $job->email === 'athlete@example.com');
        $this->assertSame(GarminLoginAttempt::STARTING, GarminLoginAttempt::currentFor($this->athlete())->status);
    }

    public function test_the_login_library_gets_its_narration_into_the_worker_log(): void
    {
        // The library tries five sign-in routes and only says which one it
        // took on standard error. Nobody read that stream unless the
        // process died, so a login left waiting for a code Garmin was
        // never asked to send had no explanation afterwards.
        //
        // Prefixed lines only: the process on the other end of that pipe
        // was handed a password, and anything it prints unprompted is
        // unknown by definition.
        config(['garmin.login.command' => 'sh -c \'printf "garmin: Trying login strategy: widget+cffi\\n" >&2; printf "unprefixed noise\\n" >&2; printf "__GARMIN__ FAILED Nope\\n"\'']);

        $attempt = $this->attempt(GarminLoginAttempt::STARTING);

        Log::spy();

        app(GarminLogin::class)->run($attempt->id, 'athlete@example.com', 'secret', $attempt->user_id);

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn ($message) => str_contains($message, 'Trying login strategy: widget+cffi'));

        Log::shouldNotHaveReceived('info', ['garmin login: unprefixed noise']);

        $this->assertSame(GarminLoginAttempt::FAILED, $attempt->refresh()->status);
    }

    public function test_the_attempt_row_has_nowhere_to_keep_a_password(): void
    {
        // Not a matter of discipline in the code: the column does not exist,
        // so no later change can quietly start storing one.
        $this->assertFalse(Schema::hasColumn('garmin_login_attempts', 'password'));
    }

    public function test_a_new_sign_in_replaces_the_one_before_it(): void
    {
        Queue::fake();
        $this->attempt(GarminLoginAttempt::FAILED, ['error' => 'Wrong password']);

        $this->actingAs($this->athlete())
            ->post('/connect/garmin', ['email' => 'athlete@example.com', 'password' => 'secret']);

        // Otherwise the fresh attempt would be shown next to the verdict of
        // the old one, and the page could not say which is current.
        $this->assertSame(1, GarminLoginAttempt::count());
    }

    public function test_too_many_attempts_are_turned_away_before_they_reach_garmin(): void
    {
        // Garmin locks an account that is tried too often, so the limiter
        // protects the athlete's account, not this server.
        Queue::fake();
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->post('/connect/garmin', ['email' => 'a@example.com', 'password' => 'x']);
        }

        $this->actingAs($user)
            ->post('/connect/garmin', ['email' => 'a@example.com', 'password' => 'x'])
            ->assertSessionHas('login_throttled');

        Queue::assertPushed(RunGarminLogin::class, 5);
    }

    public function test_the_waiting_stage_shows_only_the_wait(): void
    {
        $this->attempt(GarminLoginAttempt::STARTING);

        $this->actingAs($this->athlete())
            ->get('/connect/garmin')
            ->assertStatus(200)
            ->assertSee('Signing in to Garmin')
            ->assertDontSee('name="password"', false);
    }

    public function test_the_wait_says_how_long_it_should_take_and_counts_it_down(): void
    {
        // The wait is a minute of nothing, long enough to be read as a
        // hang. Saying what it costs is the whole difference between
        // waiting and wondering whether anything is still running.
        $this->attempt(GarminLoginAttempt::STARTING);

        $this->actingAs($this->athlete())
            ->get('/connect/garmin')
            ->assertStatus(200)
            ->assertSee('This usually takes under a minute.')
            ->assertSee('wait-bar', false)
            ->assertSee('It is taking longer than usual');
    }

    public function test_the_last_second_is_not_counted_in_the_plural(): void
    {
        // "About 1 seconds left" is the standard tell of a counter nobody
        // watched to the end. It is one second of the wait, and the wait is
        // the one part of this page a reader does watch.
        $this->attempt(GarminLoginAttempt::STARTING);

        $this->actingAs($this->athlete())
            ->get('/connect/garmin')
            ->assertStatus(200)
            ->assertSee('About one second left.')
            ->assertSee('remaining > 1', false);
    }

    public function test_the_countdown_resumes_where_a_reload_interrupted_it(): void
    {
        // Counted from the row, not from the page load: a reload halfway
        // through would otherwise promise a fresh minute every time, which
        // is the one thing a countdown must never do.
        $attempt = $this->attempt(GarminLoginAttempt::STARTING);
        $attempt->forceFill(['created_at' => now()->subSeconds(20)])->save();

        $response = $this->actingAs($this->athlete())->get('/connect/garmin');

        $response->assertStatus(200);
        $this->assertMatchesRegularExpression(
            "/loginWatch\(\s*'[^']+',\s*'starting',\s*60,\s*2[01]\s*\)/",
            $response->getContent(),
        );
    }

    public function test_the_stage_after_the_code_gets_no_countdown(): void
    {
        // Garmin answers a submitted code in a second or two. A minute-long
        // bar over it would be an invented wait.
        $this->attempt(GarminLoginAttempt::COMPLETING);

        $this->actingAs($this->athlete())
            ->get('/connect/garmin')
            ->assertStatus(200)
            ->assertSee('Garmin is confirming it')
            ->assertDontSee('wait-bar', false)
            ->assertDontSee('This usually takes under a minute.');
    }

    public function test_the_mfa_stage_asks_for_the_code(): void
    {
        $this->attempt(GarminLoginAttempt::MFA_REQUIRED);

        $this->actingAs($this->athlete())
            ->get('/connect/garmin')
            ->assertStatus(200)
            ->assertSee('Enter the code from Garmin')
            ->assertSee('autocomplete="one-time-code"', false);
    }

    /**
     * The page names the second factor instead of guessing at it.
     *
     * It used to say the code had been sent by email or text. That is
     * wrong for an authenticator app, which is never sent anywhere, and
     * someone sent to an inbox that will stay empty spends the entire
     * five-minute window before finding out.
     *
     * @return array<string, array{string, string}>
     */
    public static function channels(): array
    {
        return [
            'email' => ['method=email flow=ios', 'sent a code to the email address'],
            'text message' => ['method=sms flow=ios', 'by text message'],
            'authenticator app' => ['method=totp flow=portal', 'in the authenticator app'],
            'nothing reported' => ['', 'the second factor set up on your account'],

            // The widget route is told no method at all, so the heading of
            // the page it stopped on is the only evidence of which factor
            // Garmin means. Garmin words the two differently, and the
            // difference decides whether waiting for a code is the right
            // thing to do or a wasted five minutes.
            'authenticator app, from the page title' => [
                'method=unknown flow=widget page=Enter MFA code for login',
                'in the authenticator app',
            ],
            'emailed code, from the page title' => [
                'method=unknown flow=widget page=GARMIN Authentication Application',
                'sent a code to the email address',
            ],
            'a page title we do not know' => [
                'method=unknown flow=widget page=Something Garmin Renamed',
                'the second factor set up on your account',
            ],
        ];
    }

    #[DataProvider('channels')]
    public function test_the_mfa_stage_names_where_the_code_is_going(string $channel, string $expected): void
    {
        $this->attempt(GarminLoginAttempt::MFA_REQUIRED, ['mfa_channel' => $channel]);

        $this->actingAs($this->athlete())
            ->get('/connect/garmin')
            ->assertStatus(200)
            ->assertSee($expected)
            ->assertSee('held open for five minutes');
    }

    public function test_a_sign_in_that_cannot_confirm_delivery_says_so(): void
    {
        // The HTML fallback route scrapes a page rather than calling the
        // login API, so nothing proves Garmin was ever asked to send a
        // code. Waiting on one is then the wrong thing to do, and the
        // page is the only place that can say it.
        $this->attempt(GarminLoginAttempt::MFA_REQUIRED, [
            'mfa_channel' => 'method=unknown flow=widget delivery=unconfirmed',
        ]);

        $this->actingAs($this->athlete())
            ->get('/connect/garmin')
            ->assertStatus(200)
            ->assertSee('does not confirm it sent anything');
    }

    public function test_the_fallback_route_says_so_even_when_the_library_forgot_to(): void
    {
        // The library's own "delivery is unconfirmed" flag does not survive
        // its strategy chain: it is cleared before every strategy and left
        // out of the state restored when an earlier MFA is fallen back on,
        // so a widget sign-in that had to wait for the later strategies
        // arrives here with the warning already wiped. The route it took is
        // still on the row, and that is what the warning now hangs on.
        $this->attempt(GarminLoginAttempt::MFA_REQUIRED, [
            'mfa_channel' => 'method=unknown flow=widget page=GARMIN Authentication Application',
        ]);

        $this->actingAs($this->athlete())
            ->get('/connect/garmin')
            ->assertStatus(200)
            ->assertSee('does not confirm it sent anything');
    }

    public function test_an_authenticator_app_is_not_warned_about_a_delivery_it_never_needed(): void
    {
        // Nothing is sent for a code that is generated on the phone, so
        // there is nothing whose delivery could be in doubt. The warning
        // would only be one more sentence to read while the code expires.
        $this->attempt(GarminLoginAttempt::MFA_REQUIRED, [
            'mfa_channel' => 'method=unknown flow=widget page=Enter MFA code for login',
        ]);

        $this->actingAs($this->athlete())
            ->get('/connect/garmin')
            ->assertStatus(200)
            ->assertDontSee('does not confirm it sent anything');
    }

    public function test_the_code_is_handed_to_the_waiting_sign_in(): void
    {
        $attempt = $this->attempt(GarminLoginAttempt::MFA_REQUIRED);

        $this->actingAs($this->athlete())
            ->post('/connect/garmin/code', ['code' => ' 123456 '])
            ->assertRedirect(route('connect.garmin'));

        $this->assertSame('123456', $attempt->fresh()->mfa_code);
    }

    public function test_a_code_is_ignored_when_no_sign_in_is_waiting_for_one(): void
    {
        // Late arrival, or a stale tab: writing it anyway would leave a code
        // lying in the row that nothing will ever pick up or clear.
        $attempt = $this->attempt(GarminLoginAttempt::FAILED, ['error' => 'Wrong password']);

        $this->actingAs($this->athlete())
            ->post('/connect/garmin/code', ['code' => '123456']);

        $this->assertNull($attempt->fresh()->mfa_code);
    }

    public function test_a_finished_sign_in_names_the_account_and_folds_the_form_away(): void
    {
        $this->attempt(GarminLoginAttempt::SUCCEEDED, ['account' => 'Alex D.']);

        $this->actingAs($this->athlete())
            ->get('/connect/garmin')
            ->assertStatus(200)
            ->assertSee('Connected')
            ->assertSee('Alex D.')
            // Reachable, but no longer the thing to do next.
            ->assertSee('Sign in again');
    }

    public function test_a_failed_sign_in_shows_what_garmin_said(): void
    {
        $this->attempt(GarminLoginAttempt::FAILED, ['error' => 'Garmin refused the password.']);

        $this->actingAs($this->athlete())
            ->get('/connect/garmin')
            ->assertStatus(200)
            ->assertSee('Garmin refused the password.')
            ->assertSee('name="password"', false);
    }

    public function test_the_status_endpoint_reports_the_stage_and_whether_it_is_over(): void
    {
        $this->attempt(GarminLoginAttempt::MFA_REQUIRED);

        $this->actingAs($this->athlete())
            ->getJson('/connect/garmin/status')
            ->assertStatus(200)
            ->assertJson(['status' => GarminLoginAttempt::MFA_REQUIRED, 'finished' => false]);
    }

    public function test_the_status_endpoint_never_returns_the_code(): void
    {
        // The page polls this every two seconds; a code in the answer would
        // travel far more often than the one time it is needed.
        $this->attempt(GarminLoginAttempt::MFA_REQUIRED, ['mfa_code' => '123456']);

        $this->actingAs($this->athlete())
            ->getJson('/connect/garmin/status')
            ->assertStatus(200)
            ->assertDontSee('123456');
    }

    public function test_a_broken_login_puts_the_way_to_fix_it_on_the_dashboard(): void
    {
        // The failure this whole page exists for: before it, "Fetch from
        // Garmin" simply did nothing and said nothing.
        $this->seedBrokenLogin();

        $this->actingAs($this->athlete())
            ->get('/')
            ->assertStatus(200)
            ->assertSee('The Garmin login has expired')
            ->assertSee(route('connect.garmin'), false);
    }

    public function test_the_account_menu_only_claims_a_connection_it_can_prove(): void
    {
        // The athlete whose mirror seedWorkingFetch fills: since the mirror
        // is per user, the fetch another account made is no evidence of
        // this one's connection, and the menu is about this one's.
        $user = $this->athlete();

        // Nothing fetched, nothing failed: not evidence of a connection.
        $this->actingAs($user)->get('/')->assertSee('Connect Garmin');

        $this->seedWorkingFetch();

        $this->actingAs($user)->get('/')->assertSee('Garmin connected');
    }

    public function test_the_account_menu_drops_the_connection_when_the_session_dies(): void
    {
        $this->seedBrokenLogin();

        $this->actingAs($this->athlete())
            ->get('/')
            ->assertSee('Connect Garmin')
            ->assertDontSee('Garmin connected');
    }

    public function test_the_fetch_status_endpoint_carries_the_reason_and_the_fix(): void
    {
        // What the flash under the header polls. Without the reason it could
        // only keep saying "running" for a fetch that had already died.
        //
        // The reason is the short one, not the header's hint: the header
        // line right above the flash is already showing that hint word for
        // word, and the same sentence twice reads as two problems.
        $this->seedBrokenLogin();
        app(FetchTrigger::class)->recordFailure('GarminConnectAuthenticationError: 401');

        $this->actingAs($this->athlete())
            ->getJson('/fetch/status')
            ->assertStatus(200)
            ->assertJson([
                'state' => 'auth_broken',
                'problem' => 'The stored Garmin session no longer works.',
                'action' => 'Sign in again',
                'connect_url' => route('connect.garmin'),
            ]);
    }

    public function test_a_failure_signing_in_cannot_fix_keeps_the_message_and_drops_the_button(): void
    {
        // A fetch can also die of a timeout or a database that went away.
        // There the stored session is fine, so a sign-in button would send
        // someone to a page that cannot help, and the only thing worth
        // showing is what actually came back.
        $this->seedWorkingFetch();
        app(FetchTrigger::class)->recordFailure('OperationalError: connection timed out');

        $this->actingAs($this->athlete())
            ->getJson('/fetch/status')
            ->assertStatus(200)
            ->assertJsonPath('problem', 'OperationalError: connection timed out')
            ->assertJsonPath('action', null)
            ->assertJsonPath('connect_url', null);
    }

    public function test_the_refresh_tool_refuses_and_hands_over_the_sign_in_url(): void
    {
        // A fetch without a session cannot succeed. Saying so with the link
        // is the difference between the model guessing and the athlete
        // fixing it from the chat they are already in.
        Queue::fake();
        $this->seedBrokenLogin();

        GarminHealthServer::tool(RefreshDataTool::class, [])
            ->assertOk()
            ->assertSee('"started":false')
            ->assertSee('The Garmin login has expired')
            ->assertSee(route('connect.garmin'), false);

        Queue::assertNothingPushed();
    }
}
