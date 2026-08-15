<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * How a stranger gets into the public demo, and what that must cost
 * everybody else.
 *
 * DemoModeTest holds the doors the demo keeps shut. This holds the one
 * it has to hold open: a shop window nobody can walk into is not a shop
 * window, so the demo prints its own credentials on the sign-in page and
 * puts them in the fields. That is safe exactly once, on an installation
 * whose password comes from DEMO_PASSWORD, is shared by every visitor
 * and is written back every night.
 *
 * Everywhere else the same code would print the owner's password on
 * their own sign-in page, which is why the second test here matters more
 * than the rest of the file: it is not enough that the notice is hidden,
 * the value must not be in the document at all. A prefilled field lives
 * in an attribute, where nothing on screen would give it away and a
 * reader would find it only in view-source, or a crawler in the body.
 */
class DemoSignInTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Credentials that could not be mistaken for anything else on the
     * page, set rather than left at the config defaults: what is being
     * tested is that the configured value travels (or does not), not
     * that config/demo.php still spells demo@example.com.
     */
    private function aDemoAccount(bool $enabled): void
    {
        config([
            'demo.enabled' => $enabled,
            'demo.account.email' => 'demo@example.com',
            'demo.account.password' => 'the-shared-password',
        ]);
    }

    public function test_the_demo_sign_in_page_says_what_this_is_and_hands_over_the_account(): void
    {
        // The visitor arrived at a URL and has nobody to ask. Both
        // values stand in type as well as in the fields, because the
        // password field shows dots.
        $this->aDemoAccount(enabled: true);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee(__('Public demo'))
            ->assertSee('This installation is a public demo', false)
            ->assertSee('demo@example.com')
            ->assertSee('the-shared-password');
    }

    public function test_a_normal_sign_in_page_carries_none_of_it(): void
    {
        // The expensive mistake, in the shape it would actually arrive
        // in: an installation that carries DEMO_PASSWORD in its
        // environment (a copy of the demo's .env, a demo that was
        // switched off) but is somebody's own dashboard.
        $this->aDemoAccount(enabled: false);

        $response = $this->get(route('login'))->assertOk();

        $response->assertDontSee(__('Public demo'))
            ->assertDontSee('This installation is a public demo', false);

        // Not "not visible": not in the document.
        $html = (string) $response->getContent();
        $this->assertStringNotContainsString('the-shared-password', $html);
        $this->assertStringNotContainsString('demo@example.com', $html);

        // And the attribute is absent rather than empty, so that a
        // later edit cannot quietly start filling one that is already
        // there. This is the field an owner types their own password
        // into. Its presence is asserted first: a slice taken from a
        // field that is no longer there would carry no value= either,
        // and pass for the wrong reason.
        $this->assertStringContainsString('<input id="password"', $html);
        $passwordField = Str::before(Str::after($html, '<input id="password"'), '>');
        $this->assertStringNotContainsString('value=', $passwordField);
    }

    public function test_the_demo_fills_both_fields_so_signing_in_is_one_press(): void
    {
        // The point of the whole thing: a visitor reads the notice, then
        // presses the button, and nothing in between is typing.
        $this->aDemoAccount(enabled: true);

        $html = (string) $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringContainsString('value="demo@example.com"', $html);
        $this->assertStringContainsString('value="the-shared-password"', $html);
    }

    public function test_the_guide_sends_a_demo_visitor_to_the_sign_in_page_and_not_to_an_operator(): void
    {
        // "Whoever runs this installation hands over the password" is
        // the sentence a public demo must not say: it sends the reader
        // to a person they have no way of reaching, for something that
        // is printed one click away.
        config(['demo.enabled' => true]);

        $this->get('/')
            ->assertOk()
            ->assertSee('the sign-in page carries the password', false)
            ->assertDontSee('Whoever runs this installation creates the account', false)
            // Same for the step below it, which on a demo names a menu
            // entry that is not in the menu there.
            ->assertSee('Nothing to connect here', false)
            ->assertDontSee('Under Garmin in the account menu: your Garmin address', false);
    }

    public function test_the_demo_guide_promises_no_backfill_and_no_connection_it_cannot_make(): void
    {
        // The two steps below the sign-in ones describe a machine this
        // installation is not. Step three has a backfill starting at
        // sign-in, a page filling in while you watch and a fetch three
        // times a day: here the history was seeded before the visitor
        // arrived and is written again every night. Step four invites
        // two connections that routes/ai.php and the not-demo group
        // refuse outright. A numbered trail whose steps cannot be
        // walked reads as a broken page rather than as a restriction.
        config(['demo.enabled' => true]);

        $this->get('/')
            ->assertOk()
            ->assertSee('The history here is in place before you arrive', false)
            ->assertSee('Both are shut here', false)
            // The heading is an instruction everywhere else, and the
            // pill beside step four says "optional" where nothing is on
            // offer. Both carry the demo's answer instead.
            ->assertSee(__('The first ninety days'))
            ->assertSee(__('Closed here'))
            ->assertDontSee(__('Let the first ninety days land'))
            ->assertDontSee('That first sign-in starts a backfill', false)
            ->assertDontSee('Connect Claude or ChatGPT so the numbers', false);
    }

    public function test_a_normal_guide_keeps_all_four_steps_word_for_word(): void
    {
        config(['demo.enabled' => false]);

        $this->get('/')
            ->assertOk()
            ->assertSee(__('There is no sign-up page, on purpose: a login nobody can register at has no surface to attack. Whoever runs this installation creates the account and hands over the password.'))
            ->assertSee(__('Under Garmin in the account menu: your Garmin address, its password, and the code if Garmin asks for one. What is kept is a token pair that lasts about a year, never the password itself.'))
            ->assertSee(__('Let the first ninety days land'))
            ->assertSee(__('Optional'))
            ->assertSee('That first sign-in starts a backfill', false)
            ->assertSee('Connect Claude or ChatGPT so the numbers', false)
            ->assertDontSee('the sign-in page carries the password', false)
            ->assertDontSee('Nothing to connect here', false)
            ->assertDontSee('The history here is in place', false)
            ->assertDontSee('Both are shut here', false)
            ->assertDontSee(__('Closed here'));
    }
}
