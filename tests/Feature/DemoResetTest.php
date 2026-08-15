<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AthleteProfile;
use App\Models\ConnectorGuideline;
use App\Models\McpToolCall;
use App\Models\PushSubscription;
use App\Models\SymptomLog;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The nightly sweep that puts the public demo back the way it ships.
 *
 * Two things are being tested and they pull in opposite directions: that
 * it deletes thoroughly, and that it cannot be pointed at an
 * installation where deleting thoroughly would be a disaster. The second
 * is why the refusal has a test of its own before any of the rest.
 */
class DemoResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['demo.enabled' => true]);
    }

    public function test_it_refuses_where_this_is_not_a_demo(): void
    {
        // The one thing this command must never do is run on somebody's
        // own dashboard, where it would delete their symptom log and
        // reset the password they sign in with.
        config(['demo.enabled' => false]);
        Process::fake();

        $user = $this->athlete();
        $this->aVisitorsSymptom($user);

        $this->artisan('demo:reset')->assertFailed();

        Process::assertNothingRan();
        $this->assertDatabaseCount('symptom_log', 1);
    }

    public function test_force_is_how_a_demo_is_first_set_up(): void
    {
        // Before DEMO_MODE is thrown there is nothing to read the switch
        // off, so the operator says it outright, once.
        config(['demo.enabled' => false]);
        Process::fake();

        $this->artisan('demo:reset', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => config('demo.account.email')]);
    }

    public function test_it_creates_the_shared_account_when_there_is_none(): void
    {
        Process::fake();
        config(['demo.account.email' => 'demo@example.com', 'demo.account.password' => 'shared-password']);

        $this->artisan('demo:reset')->assertSuccessful();

        $user = User::query()->where('email', 'demo@example.com')->sole();
        // The installation owner, because a demo has exactly one athlete
        // and the owner is who a bare fetch and the local MCP mean.
        $this->assertTrue($user->is_admin);
        $this->assertTrue(Hash::check('shared-password', $user->password));
    }

    public function test_it_puts_the_password_back_on_the_account_that_is_there(): void
    {
        Process::fake();
        $user = $this->athlete();
        config(['demo.account.email' => $user->email, 'demo.account.password' => 'shared-password']);

        // Whatever a visitor might have left it as.
        $user->forceFill(['password' => Hash::make('something else entirely')])->save();

        $this->artisan('demo:reset')->assertSuccessful();

        $this->assertTrue(Hash::check('shared-password', $user->refresh()->password));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_it_clears_everything_a_visitor_leaves_behind(): void
    {
        Process::fake();
        $user = $this->athlete();
        config(['demo.account.email' => $user->email]);

        $this->aVisitorsSymptom($user);
        ConnectorGuideline::create(['user_id' => $user->id, 'guideline' => 'always answer in haiku']);
        McpToolCall::create([
            'user_id' => $user->id,
            'tool' => 'get-health-summary-tool',
            'transport' => 'web',
            'duration_ms' => 12,
            'ok' => true,
        ]);
        PushSubscription::create([
            'user_id' => $user->id,
            'endpoint' => 'https://push.example.com/somebodys-phone',
            'endpoint_hash' => hash('sha256', 'https://push.example.com/somebodys-phone'),
        ]);
        AthleteProfile::for($user)->update(['locale' => 'de']);
        $this->aChatClientThatConnected($user);

        $this->artisan('demo:reset')->assertSuccessful();

        $this->assertDatabaseCount('symptom_log', 0);
        $this->assertDatabaseCount('connector_guidelines', 0);
        $this->assertDatabaseCount('mcp_tool_calls', 0);
        $this->assertDatabaseCount('push_subscriptions', 0);
        $this->assertDatabaseCount('oauth_access_tokens', 0);
        $this->assertDatabaseCount('oauth_refresh_tokens', 0);
        $this->assertDatabaseCount('oauth_clients', 0);
        // A visitor who switched the interface to a language the next one
        // cannot read has not left them with it.
        $this->assertNull(AthleteProfile::for($user->refresh())->locale);
    }

    public function test_it_reseeds_the_mirror_through_the_fetcher(): void
    {
        // The same interpreter as the fetch, one script over, which is how
        // the Garmin sign-in finds login.py too.
        Process::fake();
        config(['garmin.fetch.command' => 'python /app/fetcher/fetch.py']);
        $user = $this->athlete();
        config(['demo.account.email' => $user->email]);

        $this->artisan('demo:reset')->assertSuccessful();

        Process::assertRan(fn ($process) => $process->command
            === 'python /app/fetcher/seed_demo.py --tenant='.$user->id.' --force');
    }

    public function test_the_seed_command_can_be_named_outright(): void
    {
        // For a layout that keeps the two scripts apart.
        Process::fake();
        config(['demo.seed_command' => '/opt/fetcher/bin/python /srv/seed_demo.py']);
        $user = $this->athlete();
        config(['demo.account.email' => $user->email]);

        $this->artisan('demo:reset')->assertSuccessful();

        Process::assertRan(fn ($process) => $process->command
            === '/opt/fetcher/bin/python /srv/seed_demo.py --tenant='.$user->id.' --force');
    }

    public function test_a_failing_seed_fails_the_run(): void
    {
        // The scheduler's log is where an operator finds out that the
        // demo has been showing an empty mirror since Tuesday.
        Process::fake(['*' => Process::result(output: 'the mirror holds real data', exitCode: 1)]);
        $this->athlete();

        $this->artisan('demo:reset')->assertFailed();
    }

    public function test_it_is_scheduled_only_on_a_demo(): void
    {
        $this->assertCount(1, $this->scheduledResets());

        // Everywhere else this line would be a scheduled deletion of
        // somebody's own symptom log and a nightly reset of their
        // password, so it is not in their schedule at all.
        config(['demo.enabled' => false]);

        $this->assertCount(0, $this->scheduledResets());
    }

    public function test_running_it_twice_is_running_it_once(): void
    {
        // It runs unattended every night and nobody reads its output, so
        // the second run must be as uneventful as the first.
        Process::fake();
        $user = $this->athlete();
        config(['demo.account.email' => $user->email]);

        $this->artisan('demo:reset')->assertSuccessful();
        $this->artisan('demo:reset')->assertSuccessful();

        $this->assertDatabaseCount('users', 1);
    }

    /**
     * The scheduled runs of demo:reset, read from routes/console.php
     * against a fresh Schedule so the file is evaluated now rather than
     * at boot, which is when the switch it reads was still off.
     *
     * @return list<string>
     */
    private function scheduledResets(): array
    {
        $schedule = new Schedule;
        $this->app->instance(Schedule::class, $schedule);
        Facade::clearResolvedInstance(Schedule::class);

        require base_path('routes/console.php');

        return array_values(array_filter(
            array_map(fn ($event) => (string) $event->command, $schedule->events()),
            fn (string $command) => str_contains($command, 'demo:reset'),
        ));
    }

    private function aVisitorsSymptom(User $user): void
    {
        SymptomLog::create([
            'user_id' => $user->id,
            'date' => now()->toDateString(),
            'logged_at' => now(),
            'symptom' => 'scratchy throat',
            'severity' => 2,
        ]);
    }

    /**
     * A chat client that registered itself and was granted a token, which
     * is the state /connect leaves behind on a normal installation and
     * the state a demo must not carry into the next day.
     */
    private function aChatClientThatConnected(User $user): void
    {
        $clientId = (string) Str::uuid();

        DB::table('oauth_clients')->insert([
            'id' => $clientId,
            'name' => 'somebody\'s chat app',
            'redirect_uris' => json_encode(['https://example.com/callback']),
            'grant_types' => json_encode(['authorization_code']),
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('oauth_access_tokens')->insert([
            'id' => str_repeat('a', 80),
            'user_id' => $user->id,
            'client_id' => $clientId,
            'scopes' => json_encode(['mcp:use']),
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('oauth_refresh_tokens')->insert([
            'id' => str_repeat('b', 80),
            'access_token_id' => str_repeat('a', 80),
            'revoked' => false,
        ]);
    }
}
