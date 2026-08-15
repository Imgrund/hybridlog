<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The front door.
 *
 * "/" used to bounce anybody without a session to the login form, which
 * answered the one question a visitor does not have. It now answers the
 * ones they do: what this is, and that the account comes from whoever
 * runs the installation, because there is no sign-up page to find.
 *
 * What is pinned here is the door itself. The dashboard is unchanged
 * behind it, and the guide must stay the page that reads nothing: no
 * mirror, no athlete, not even a user table with a row in it.
 */
class GuidePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_without_an_account_gets_the_guide(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee(__('Your watch has the numbers. This has the reading.'))
            ->assertSee(__('How to get going'))
            ->assertSee(__('Get an account'))
            ->assertSee(__('Sign in and connect Garmin'))
            ->assertSee(__('Let the first ninety days land'))
            ->assertSee(__('Bring your chat and your phone'));
    }

    public function test_the_guide_stands_on_an_installation_that_has_no_accounts_yet(): void
    {
        // The first thing a fresh deployment serves, before anybody has
        // run app:create-user. Nothing on this page may reach for the
        // installation owner, because at this moment there is none.
        $this->assertSame(0, User::query()->count());

        $this->get('/')->assertOk();
    }

    public function test_an_athlete_with_a_session_still_lands_on_the_dashboard(): void
    {
        $this->actingAs($this->athlete())->get('/')
            ->assertOk()
            ->assertSee('id="panel-koerperkarte"', false)
            ->assertDontSee(__('How to get going'));
    }

    public function test_the_guide_carries_exactly_one_action(): void
    {
        // The one-action rule, as far as a test can hold it: the
        // page offers the sign-in and nothing else to press. A second
        // primary button would be the first thing to break it.
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertSame(1, substr_count((string) $html, 'btn-primary'));
        $this->assertStringContainsString('href="'.route('login').'"', (string) $html);
    }

    public function test_the_trail_keeps_the_label_its_wide_column_stands_on(): void
    {
        // resources/css/app.css gives this section two columns only when
        // a label stands in the first one; without it the trail runs the
        // full width instead. That is right for /setup, which has no
        // label and nothing else on the page, and wrong here, where the
        // plate above sets the narrower rhythm. Pinned because the
        // dependency lives in a stylesheet and cannot be seen from here.
        $html = (string) $this->get('/')->assertOk()->getContent();

        $section = Str::after($html, 'class="guide-how"');

        $this->assertStringContainsString('guide-section-label', Str::before($section, '<ol'));
    }

    public function test_the_guide_speaks_the_language_the_browser_asks_for(): void
    {
        // There is no reader to have a stored choice yet, so the header
        // is all the door has to go on.
        $this->withHeader('Accept-Language', 'de-DE,de;q=0.9')->get('/')
            ->assertOk()
            ->assertSee('So legst du los')
            ->assertSee('lang="de"', false);
    }
}
