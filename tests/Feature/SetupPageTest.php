<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The page an invited athlete is sent to.
 *
 * Its whole purpose is to be a link somebody can be handed before they
 * have signed in even once, so the thing most worth pinning is that it
 * answers without a session and still carries the connector address.
 */
class SetupPageTest extends TestCase
{
    use RefreshDatabase;

    /** A host claude.ai could actually reach, which the prefill needs. */
    private function onARealHost(): void
    {
        URL::forceRootUrl('https://hybridlog.example.com');
        URL::forceScheme('https');
    }

    public function test_it_answers_without_a_session(): void
    {
        $this->onARealHost();

        $this->get('/setup')
            ->assertStatus(200)
            ->assertSee('Getting set up')
            ->assertSee('Connect your own Garmin account');
    }

    public function test_it_carries_the_connector_address(): void
    {
        $this->onARealHost();

        $this->get('/setup')
            ->assertStatus(200)
            ->assertSee('https://hybridlog.example.com/mcp/garmin');
    }

    public function test_it_offers_the_prefilled_claude_dialog(): void
    {
        $this->onARealHost();

        $html = $this->get('/setup')->getContent();

        $this->assertStringContainsString('claude.ai/customize/connectors', $html);
        $this->assertStringContainsString('connectorName=hybridlog', $html);
    }

    public function test_it_withholds_the_prefill_for_an_address_claude_cannot_reach(): void
    {
        // Same rule as /connect: claude.ai resolves localhost against its
        // own machine, so the button would hand over a dialog that fails
        // on submit.
        URL::forceRootUrl('http://localhost:8000');

        $html = $this->get('/setup')->getContent();

        $this->assertStringNotContainsString('claude.ai/customize/connectors', $html);
        // The address itself still shows, so the reader can see for
        // themselves that it is a local one.
        $this->assertStringContainsString('localhost:8000/mcp/garmin', $html);
    }

    public function test_the_sign_in_is_the_pages_one_primary_action(): void
    {
        // Exactly one acid-lime primary owns a viewport. The
        // Claude button is deliberately the quieter one, being both
        // optional and the fourth step.
        $this->onARealHost();

        $html = $this->get('/setup')->getContent();

        $this->assertSame(1, substr_count($html, 'btn-primary'));
    }

    public function test_the_guide_points_at_it(): void
    {
        // A page nobody can find is a page nobody reads: the front door
        // has to lead here for somebody who arrives without the link.
        $this->get('/')
            ->assertStatus(200)
            ->assertSee(route('setup'))
            ->assertSee('Step by step, with the click paths');
    }

    /** The public demo, where three of the four steps are closed. */
    private function onTheDemo(): void
    {
        config(['demo.enabled' => true]);
        $this->onARealHost();
    }

    public function test_the_demo_does_not_walk_anybody_through_a_setup_it_forbids(): void
    {
        // Garmin, the town and the connector all sit behind not-demo
        // middleware, so instructing them would send a visitor into
        // three refusals in a row.
        $this->onTheDemo();

        $this->get('/setup')
            ->assertStatus(200)
            ->assertDontSee(__('Connect your own Garmin account'))
            ->assertDontSee(__('Say where you train'))
            ->assertDontSee(__('By hand, in Claude:'));
    }

    public function test_the_demo_withholds_the_connector_address_entirely(): void
    {
        // It answers 403 there, not the 401 that starts a sign-in, so
        // printing it would be an invitation to a closed door. The
        // prefill button goes with it.
        $this->onTheDemo();

        $html = $this->get('/setup')->getContent();

        $this->assertStringNotContainsString('/mcp/garmin', $html);
        $this->assertStringNotContainsString('claude.ai/customize/connectors', $html);
    }

    public function test_the_demo_says_what_it_is_and_where_the_real_thing_lives(): void
    {
        $this->onTheDemo();

        $this->get('/setup')
            ->assertStatus(200)
            ->assertSee(__('Nothing to set up here.'))
            ->assertSee(__('Public demo'))
            ->assertSee(__('To use these, run your own copy'));
    }

    public function test_the_demo_promises_no_chat_connection_it_cannot_give(): void
    {
        // The footer offers to revoke a connection that cannot be made
        // here. What is true of every copy stays.
        $this->onTheDemo();

        $this->get('/setup')
            ->assertDontSee(__('Garmin data is read-only here, the chat connection can be revoked in one click and nothing this computes is a medical statement.'))
            ->assertSee(__('Nothing this computes is a medical statement.'));
    }

    public function test_the_demo_keeps_the_pages_one_primary_action(): void
    {
        $this->onTheDemo();

        $this->assertSame(1, substr_count($this->get('/setup')->getContent(), 'btn-primary'));
    }

    public function test_it_still_answers_for_somebody_who_is_signed_in(): void
    {
        // Half the steps are done with a session, so the link has to keep
        // working after step one rather than bouncing to the dashboard.
        $this->onARealHost();

        $this->actingAs(User::factory()->create())
            ->get('/setup')
            ->assertStatus(200)
            ->assertSee('Say where you train');
    }
}
