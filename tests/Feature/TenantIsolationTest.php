<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\GarminHealthServer;
use App\Mcp\Tools\DeleteSymptomTool;
use App\Mcp\Tools\GetHealthSummaryTool;
use App\Mcp\Tools\GiveFeedbackTool;
use App\Mcp\Tools\LogSymptomTool;
use App\Models\AthleteProfile;
use App\Models\ConnectorGuideline;
use App\Models\ConnectorSettings;
use App\Models\McpToolCall;
use App\Models\PushSubscription;
use App\Models\SymptomLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The promise the app schema has to keep: two athletes on one
 * installation, and neither can read or flip anything of the other's.
 *
 * Every case here is written from B's side reaching for A's data, because
 * that is the direction that matters: a scoping bug shows up as B seeing
 * something, never as A losing something. The mirror itself is still
 * shared at this stage, so nothing
 * here asserts about Garmin rows.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $a;

    private User $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = User::factory()->admin()->create();
        $this->b = User::factory()->create();
    }

    public function test_the_connector_switches_are_per_user(): void
    {
        ConnectorSettings::for($this->a)->update(['allow_symptoms' => false]);

        // B's own switches start at the column defaults, untouched by A.
        $this->assertTrue(ConnectorSettings::for($this->b)->allow_symptoms);

        $this->actingAs($this->b)->post('/connect/permissions', [
            'share_health_data' => '1',
        ])->assertRedirect('/connect');

        // B flipping their own switches leaves A's alone, in both
        // directions: A stays off where B is on, and on where B is off.
        $this->assertFalse(ConnectorSettings::for($this->a)->fresh()->allow_symptoms);
        $this->assertTrue(ConnectorSettings::for($this->a)->fresh()->share_body_metrics);
        $this->assertFalse(ConnectorSettings::for($this->b)->fresh()->share_body_metrics);
    }

    public function test_the_interface_language_is_per_user(): void
    {
        AthleteProfile::for($this->a)->update(['locale' => 'de']);

        // The stored choice still reaches the middleware, which now reads
        // it off the signed-in user rather than off the one profile row:
        // A gets German ...
        $this->actingAs($this->a)->get('/profile')
            ->assertStatus(200)
            ->assertSee('Sprache');

        // ... and A's German must not follow B around the dashboard.
        $this->actingAs($this->b)->get('/profile')
            ->assertStatus(200)
            ->assertSee('Language');

        $this->actingAs($this->b)->post('/profile/language', ['locale' => 'en'])
            ->assertRedirect('/profile');

        $this->assertSame('de', AthleteProfile::for($this->a)->fresh()->locale);
    }

    public function test_a_symptom_logged_by_one_athlete_is_invisible_to_the_other(): void
    {
        GarminHealthServer::actingAs($this->a)->tool(LogSymptomTool::class, [
            'symptom' => 'scratchy throat',
            'body_zone' => 'KNEE',
        ]);

        $entry = SymptomLog::sole();
        $this->assertSame($this->a->id, $entry->user_id);

        // Not in B's summary ...
        GarminHealthServer::actingAs($this->b)
            ->tool(GetHealthSummaryTool::class, ['days' => 7])
            ->assertDontSee('scratchy throat');

        // ... not on B's body map ...
        $this->assertSame([], SymptomLog::byZone($this->b));
        $this->assertNotSame([], SymptomLog::byZone($this->a));

        // ... and B cannot delete it either: the id is not theirs to name.
        GarminHealthServer::actingAs($this->b)
            ->tool(DeleteSymptomTool::class, ['id' => $entry->id])
            ->assertHasErrors();

        $this->assertNotNull($entry->fresh());
    }

    public function test_a_guideline_reaches_only_its_own_connector(): void
    {
        GarminHealthServer::actingAs($this->a)->tool(GiveFeedbackTool::class, [
            'feedback' => 'Nenn mir immer die Zahl dazu.',
            'guideline' => 'Always name the number behind a verdict.',
        ]);

        $guideline = ConnectorGuideline::sole();
        $this->assertSame($this->a->id, $guideline->user_id);

        $this->assertStringContainsString(
            'Always name the number behind a verdict.',
            ConnectorGuideline::instructionsBlock($this->a)
        );
        $this->assertSame('', ConnectorGuideline::instructionsBlock($this->b));

        // B can neither retire it through the chat ...
        GarminHealthServer::actingAs($this->b)->tool(GiveFeedbackTool::class, [
            'feedback' => 'Weg damit.',
            'retire_guideline_id' => $guideline->id,
        ])->assertHasErrors();

        // ... nor delete it from their own connector page.
        $this->actingAs($this->b)
            ->post(route('connect.guidelines.delete', $guideline))
            ->assertNotFound();

        $this->assertNull($guideline->fresh()->retired_at);
        $this->assertSame(1, ConnectorGuideline::count());
    }

    public function test_a_device_belongs_to_the_athlete_who_subscribed_it(): void
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/device-a';
        PushSubscription::remember($endpoint, 'iPhone', $this->a);

        // B's settings page lists their own devices, which is none.
        $this->actingAs($this->b)->get('/connect/notifications')
            ->assertStatus(200)
            ->assertDontSee('iPhone');

        // An endpoint B does not own cannot be unsubscribed by B: the
        // desired state is "B is not subscribed", and A's phone keeps
        // its notifications.
        $this->actingAs($this->b)->postJson('/push/unsubscribe', ['endpoint' => $endpoint])
            ->assertOk();

        $this->assertSame(1, PushSubscription::count());
        $this->assertSame($this->a->id, PushSubscription::sole()->user_id);
    }

    public function test_the_push_feed_only_carries_what_was_sent_to_this_athlete(): void
    {
        // A got today's alert; B's phone was never woken, so B's feed
        // must stay empty rather than serve A's readiness.
        $this->a->healthAlerts()->create([
            'rule' => 'readiness',
            'date' => now()->toDateString(),
            'message' => 'Readiness 21: zone 1 only today.',
        ]);

        $this->actingAs($this->b)->getJson('/push/next')
            ->assertOk()
            ->assertJsonPath('window', null);

        $this->actingAs($this->a)->getJson('/push/next')
            ->assertJsonPath('window.type', 'health-alert')
            ->assertJsonPath('window.body', 'Readiness 21: zone 1 only today.');
    }

    public function test_a_tool_call_without_any_user_fails_closed(): void
    {
        // The fail-closed rule of the plan: no resolvable tenant, no
        // data. Nothing may fall back to whoever happens to be first in
        // the table, so this test takes away the one identity the local
        // transport is allowed to resolve: the installation owner.
        User::query()->update(['is_admin' => false]);

        GarminHealthServer::tool(LogSymptomTool::class, ['symptom' => 'Husten'])
            ->assertHasErrors();

        $this->assertSame(0, SymptomLog::count());

        // The refusal is still recorded, without a tenant: a connector
        // reaching an installation it has no account on is exactly the
        // call worth finding in the log afterwards.
        $call = McpToolCall::sole();
        $this->assertNull($call->user_id);
        $this->assertFalse($call->ok);
    }

    public function test_the_dashboard_and_the_connector_page_stay_behind_the_login(): void
    {
        // The web half of the same rule: an unauthenticated request has
        // no tenant, and every page that reads one is closed to it.
        //
        // "/" is the exception in form and not in substance. It answers
        // a guest with the guide, which reads no mirror and names no
        // athlete, so what is asserted here is that the answer holds
        // none of the dashboard rather than that it is a redirect.
        $this->get('/')
            ->assertOk()
            ->assertDontSee('id="panel-koerperkarte"', false)
            ->assertDontSee($this->a->email)
            ->assertDontSee($this->b->email);
        $this->get('/connect')->assertRedirect('/login');
        $this->get('/connect/notifications')->assertRedirect('/login');
        $this->getJson('/push/next')->assertUnauthorized();
    }
}
