<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The one-click link into claude.ai's add-connector dialog.
 *
 * Anthropic documents the parameters and hardly anybody uses them, so
 * the usual instruction is three steps and a copied address. The link
 * only prefills the dialog: the athlete still reads the address and
 * still approves the grant, which is what keeps a one-tap button
 * honest here.
 *
 * The interesting case is the one where it must NOT appear. `localhost`
 * in this dashboard's URL is the address of whatever machine claude.ai
 * fetches from, not of this installation, so the prefilled dialog would
 * fail on submit: a button that cannot work is worse than no button.
 */
class ClaudeConnectorLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_reachable_installation_offers_the_one_click_link(): void
    {
        // The address comes from the URL generator, which follows the
        // request's host rather than app.url, so that is what a test
        // about the address has to move. Scheme is a second setting:
        // forceRootUrl alone leaves it at the request's http.
        URL::forceRootUrl('https://hybridlog.example.com');
        URL::forceScheme('https');

        $this->actingAs($this->athlete())
            ->get('/connect')
            ->assertOk()
            ->assertSee('https://claude.ai/customize/connectors', false)
            ->assertSee('modal=add-custom-connector', false)
            // The address travels percent-encoded as one query value.
            ->assertSee(urlencode('https://hybridlog.example.com/mcp/garmin'), false);
    }

    public function test_the_link_does_not_take_the_pages_one_primary_action(): void
    {
        // Exactly one primary action per viewport, and
        // on this page it belongs to Save. The link is the quicker path
        // but only for one of three clients, so giving it the accent
        // would rank the client instead of the step.
        URL::forceRootUrl('https://hybridlog.example.com');
        URL::forceScheme('https');

        $html = (string) $this->actingAs($this->athlete())->get('/connect')->getContent();

        $this->assertSame(1, substr_count($html, 'btn-primary'));
    }

    public function test_a_local_installation_offers_the_address_instead_of_a_link(): void
    {
        // The suite's own default host, which is the laptop case.
        $response = $this->actingAs($this->athlete())->get('/connect');

        $response->assertOk()
            ->assertDontSee('claude.ai/customize/connectors', false)
            // The manual path stays, because it is the one that works.
            ->assertSee('http://localhost/mcp/garmin', false);
    }

    public function test_the_loopback_address_counts_as_local_too(): void
    {
        URL::forceRootUrl('http://127.0.0.1:8080');

        $this->actingAs($this->athlete())
            ->get('/connect')
            ->assertOk()
            ->assertDontSee('claude.ai/customize/connectors', false);
    }
}
