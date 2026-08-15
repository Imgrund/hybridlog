<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\HealthAlert;
use App\Models\PushSend;
use App\Models\PushSubscription;
use App\Models\User;
use App\Push\EveningNudge;
use App\Push\MorningBriefing;
use App\Push\Vapid;
use App\Tenancy\ActingUser;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The promise the push layer has to keep: the four scheduled
 * senders speak to each athlete about their own body.
 *
 * They used to name `User::owner()` outright, which was honest while the
 * installation had one mirror: whoever else had an account heard nothing,
 * deliberately, rather than hearing the owner's readiness. Now each runs
 * through the athletes in turn inside ActingUser::for, so what is composed
 * and what is delivered belong to the same person.
 *
 * The composers themselves are tested elsewhere (MorningBriefingTest and
 * friends). What is pinned here is whose context they compose in, which is
 * the thing under test, so the briefing and the nudge are stood in for
 * by doubles that report the athlete they were called for. Health alerts
 * are the exception and run for real: they read the mirror directly, so
 * two seeded mirrors are the only way to know the rule judged the right
 * one.
 */
class PushTenancyTest extends TestCase
{
    use RefreshDatabase;

    private static array $keys;

    private User $a;

    private User $b;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$keys = Vapid::generate();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-07-30 09:40:00');
        Http::fake();

        config([
            'push.vapid.public_key' => self::$keys['public'],
            'push.vapid.private_key' => self::$keys['private'],
            'push.vapid.subject' => 'mailto:athlete@example.com',
        ]);

        $this->a = $this->athlete();
        $this->b = User::factory()->create();

        PushSubscription::remember('https://fcm.googleapis.com/fcm/send/device-a', 'A phone', $this->a);
        PushSubscription::remember('https://fcm.googleapis.com/fcm/send/device-b', 'B phone', $this->b);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A briefing composer that answers with whoever it was called for.
     *
     * @param  callable(int): (array<string, string>|null)  $answer
     */
    private function fakeBriefing(callable $answer): void
    {
        $this->app->instance(MorningBriefing::class, new class($answer) extends MorningBriefing
        {
            public function __construct(private $answer) {}

            public function compose(?Carbon $now = null): ?array
            {
                return ($this->answer)(ActingUser::require()->id);
            }
        });
    }

    public function test_each_athlete_is_briefed_about_their_own_morning(): void
    {
        $composedFor = [];

        $this->fakeBriefing(function (int $tenant) use (&$composedFor): array {
            $composedFor[] = $tenant;

            return ['title' => 'Morning', 'body' => 'for '.$tenant, 'url' => '/'];
        });

        $this->artisan('app:morning-briefing')->assertSuccessful();

        // Composed inside each athlete's own context, which is what makes
        // the numbers theirs: everything under the composer reads the
        // mirror through App\Garmin\Mirror and resolves the acting user.
        $this->assertSame([$this->a->id, $this->b->id], $composedFor);

        $this->assertNotNull(PushSend::sentToday(PushSend::KIND_BRIEFING, $this->a));
        $this->assertNotNull(PushSend::sentToday(PushSend::KIND_BRIEFING, $this->b));
    }

    public function test_an_athlete_with_nothing_to_say_does_not_silence_the_others(): void
    {
        // A watch that has not synced this morning is one athlete's
        // problem. Before the senders iterated, it was everybody's,
        // because there was only one of them to be silent.
        $this->fakeBriefing(fn (int $tenant) => $tenant === $this->a->id
            ? null
            : ['title' => 'Morning', 'body' => 'for '.$tenant, 'url' => '/']);

        $this->artisan('app:morning-briefing')->assertSuccessful();

        $this->assertNull(PushSend::sentToday(PushSend::KIND_BRIEFING, $this->a));
        $this->assertNotNull(PushSend::sentToday(PushSend::KIND_BRIEFING, $this->b));
    }

    public function test_one_athletes_failure_does_not_cost_the_next_their_briefing(): void
    {
        // An unreachable mirror, a half-provisioned account. The run says
        // so and carries on rather than ending at the first athlete.
        $this->fakeBriefing(function (int $tenant) {
            if ($tenant === $this->a->id) {
                throw new RuntimeException('this mirror is not there');
            }

            return ['title' => 'Morning', 'body' => 'for '.$tenant, 'url' => '/'];
        });

        $this->artisan('app:morning-briefing')
            ->expectsOutputToContain('user '.$this->a->id.': this mirror is not there')
            ->assertSuccessful();

        $this->assertNotNull(PushSend::sentToday(PushSend::KIND_BRIEFING, $this->b));
    }

    public function test_the_evening_nudge_reaches_each_athlete_separately(): void
    {
        $composedFor = [];

        $this->app->instance(EveningNudge::class, new class($composedFor) extends EveningNudge
        {
            public function __construct(private &$composedFor) {}

            public function compose(?Carbon $now = null): ?array
            {
                $this->composedFor[] = ActingUser::require()->id;

                return ['title' => 'Evening', 'body' => 'wind down', 'url' => '/'];
            }
        });

        $this->artisan('app:evening-nudge')->assertSuccessful();

        $this->assertSame([$this->a->id, $this->b->id], $composedFor);
        $this->assertNotNull(PushSend::sentToday(PushSend::KIND_NUDGE, $this->a));
        $this->assertNotNull(PushSend::sentToday(PushSend::KIND_NUDGE, $this->b));
    }

    public function test_the_weekly_reminder_rings_every_athlete(): void
    {
        Carbon::setTestNow('2026-08-02 19:00:00'); // a Sunday

        $this->artisan('app:weekly-report-reminder')->assertSuccessful();

        foreach ([$this->a, $this->b] as $athlete) {
            $this->assertTrue(
                PushSend::query()->for($athlete)->where('kind', PushSend::KIND_WEEKLY)->exists(),
                'user '.$athlete->id.' was not reminded'
            );
        }
    }

    public function test_a_ledger_entry_of_one_athlete_does_not_silence_another(): void
    {
        // Each athlete's "already sent today" is their own row. Shared,
        // whoever was briefed first would have muted everybody after them
        // for the rest of the day.
        PushSend::record(PushSend::KIND_BRIEFING, 1, $this->a);

        $composedFor = [];
        $this->fakeBriefing(function (int $tenant) use (&$composedFor): array {
            $composedFor[] = $tenant;

            return ['title' => 'Morning', 'body' => 'for '.$tenant, 'url' => '/'];
        });

        $this->artisan('app:morning-briefing')->assertSuccessful();

        $this->assertSame([$this->b->id], $composedFor);
    }

    public function test_an_alert_is_recorded_against_the_athlete_it_fired_for(): void
    {
        // The one sender that reads the mirror itself, so this runs for
        // real over two seeded mirrors: A's readiness is under the floor,
        // B's is not, and only one of them may end up with an alert.
        $this->seedMirror('readiness', [[
            'date' => now()->toDateString(),
            'score' => 12,
            'fetched_at' => now()->format('Y-m-d\TH:i:s'),
        ]], $this->a);

        $this->seedMirror('readiness', [[
            'date' => now()->toDateString(),
            'score' => 88,
            'fetched_at' => now()->format('Y-m-d\TH:i:s'),
        ]], $this->b);

        $this->artisan('app:health-alerts')->assertSuccessful();

        $this->assertTrue(HealthAlert::for($this->a)->where('rule', 'readiness')->exists());
        $this->assertFalse(HealthAlert::for($this->b)->where('rule', 'readiness')->exists());
    }

    public function test_acting_for_an_athlete_is_undone_even_when_the_work_throws(): void
    {
        // The guarantee the senders lean on: a failure for one athlete
        // must not leave the loop acting as them for the next.
        //
        // A console run with nobody named falls back to the installation
        // owner, which is A here, so that is what the context has to be
        // again afterwards, not merely "not B".
        $this->assertSame($this->a->id, ActingUser::require()->id);

        try {
            ActingUser::for($this->b, function () {
                $this->assertSame($this->b->id, ActingUser::require()->id);

                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // Expected: what matters is the state afterwards.
        }

        $this->assertSame($this->a->id, ActingUser::require()->id);
    }

    public function test_naming_an_athlete_nests_and_unwinds_in_order(): void
    {
        ActingUser::for($this->a, function () {
            $this->assertSame($this->a->id, ActingUser::require()->id);

            ActingUser::for($this->b, fn () => $this->assertSame($this->b->id, ActingUser::require()->id));

            $this->assertSame($this->a->id, ActingUser::require()->id);
        });
    }
}
