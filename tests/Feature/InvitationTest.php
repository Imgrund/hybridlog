<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * The invited person's way in.
 *
 * This is the only route in the application that creates an account, so
 * most of what is pinned here is what it refuses. The installation's
 * standing promise is that its login has no registration behind it, and
 * an invitation must narrow that to one address once rather than widen
 * it to anybody who finds the URL.
 */
class InvitationTest extends TestCase
{
    use RefreshDatabase;

    /** An issued invitation and the token that opens it. */
    private function invited(string $email = 'yvonne@example.com'): array
    {
        return Invitation::issue($email, 'Yvonne', 7);
    }

    public function test_the_command_prints_a_link_that_works(): void
    {
        $this->artisan('app:invite', ['email' => 'yvonne@example.com', '--name' => 'Yvonne'])
            ->assertSuccessful();

        $this->assertDatabaseCount('invitations', 1);
        // The link is the credential, so the row must not be one: what
        // is stored has to be useless to somebody reading the table.
        $stored = Invitation::query()->sole();
        $this->assertSame(64, strlen($stored->token_hash));
        $this->assertDatabaseMissing('invitations', ['token_hash' => 'yvonne@example.com']);
    }

    public function test_the_command_refuses_an_address_that_already_has_an_account(): void
    {
        // Not a password reset. An invitation issued here would fail on
        // redemption for a reason its holder could not see.
        User::factory()->create(['email' => 'yvonne@example.com']);

        $this->artisan('app:invite', ['email' => 'yvonne@example.com'])->assertFailed();

        $this->assertDatabaseCount('invitations', 0);
    }

    public function test_inviting_twice_replaces_the_first_link(): void
    {
        [, $first] = $this->invited();
        [, $second] = $this->invited();

        $this->assertDatabaseCount('invitations', 1);
        $this->get(route('invite.show', ['token' => $first]))->assertNotFound();
        $this->get(route('invite.show', ['token' => $second]))->assertOk();
    }

    public function test_a_holder_sets_their_own_password_and_lands_on_the_setup_page(): void
    {
        [, $token] = $this->invited();

        $this->get(route('invite.show', ['token' => $token]))
            ->assertOk()
            ->assertSee('yvonne@example.com');

        $this->post(route('invite.accept', ['token' => $token]), [
            'password' => 'a-password-of-her-own',
            'password_confirmation' => 'a-password-of-her-own',
        ])->assertRedirect(route('setup'));

        $user = User::query()->where('email', 'yvonne@example.com')->sole();
        $this->assertSame('Yvonne', $user->name);
        // Signed in already: sending somebody who just chose a password
        // to a login form would be asking for it twice.
        $this->assertTrue(Auth::check());
        $this->assertSame($user->id, Auth::id());
    }

    public function test_the_account_it_creates_is_not_an_owner(): void
    {
        // app:create-user grants that with --admin, and an invitation
        // has no equivalent on purpose: the person handing out links is
        // the owner, the person taking one is a guest of the machine.
        [, $token] = $this->invited();

        $this->post(route('invite.accept', ['token' => $token]), [
            'password' => 'a-password-of-her-own',
            'password_confirmation' => 'a-password-of-her-own',
        ]);

        $this->assertFalse((bool) User::query()->where('email', 'yvonne@example.com')->sole()->is_admin);
    }

    public function test_a_link_dies_when_it_is_used(): void
    {
        [, $token] = $this->invited();
        $accept = fn () => $this->post(route('invite.accept', ['token' => $token]), [
            'password' => 'a-password-of-her-own',
            'password_confirmation' => 'a-password-of-her-own',
        ]);

        $accept();
        Auth::logout();

        $accept()->assertNotFound();
        $this->get(route('invite.show', ['token' => $token]))->assertNotFound();
        $this->assertSame(1, User::query()->where('email', 'yvonne@example.com')->count());
    }

    public function test_a_link_dies_of_old_age(): void
    {
        [$invitation, $token] = $this->invited();
        $invitation->forceFill(['expires_at' => Carbon::now()->subMinute()])->save();

        $this->get(route('invite.show', ['token' => $token]))->assertNotFound();
        $this->post(route('invite.accept', ['token' => $token]), [
            'password' => 'a-password-of-her-own',
            'password_confirmation' => 'a-password-of-her-own',
        ])->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_token_nobody_issued_is_a_dead_end(): void
    {
        // 404 rather than an explanation: there is nothing to tell the
        // holder of a bad link that would not also tell somebody
        // guessing which guess was closest.
        $this->get(route('invite.show', ['token' => str_repeat('x', 48)]))->assertNotFound();
    }

    public function test_a_short_password_does_not_make_an_account(): void
    {
        [, $token] = $this->invited();

        $this->post(route('invite.accept', ['token' => $token]), [
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
        // The link survives a typo rather than being spent on it.
        $this->get(route('invite.show', ['token' => $token]))->assertOk();
    }

    public function test_a_mistyped_confirmation_does_not_make_an_account(): void
    {
        [, $token] = $this->invited();

        $this->post(route('invite.accept', ['token' => $token]), [
            'password' => 'a-password-of-her-own',
            'password_confirmation' => 'a-password-of-her-uwn',
        ])->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_the_address_is_the_owners_choice_and_not_the_holders(): void
    {
        // The whole difference between an invitation and a sign-up: a
        // posted email must change nothing.
        [, $token] = $this->invited();

        $this->post(route('invite.accept', ['token' => $token]), [
            'email' => 'somebody@else.example',
            'password' => 'a-password-of-her-own',
            'password_confirmation' => 'a-password-of-her-own',
        ]);

        $this->assertDatabaseHas('users', ['email' => 'yvonne@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'somebody@else.example']);
    }

    public function test_the_demo_mints_no_real_accounts(): void
    {
        config(['demo.enabled' => true]);
        [, $token] = $this->invited();

        $this->get(route('invite.show', ['token' => $token]))->assertForbidden();
        $this->post(route('invite.accept', ['token' => $token]), [
            'password' => 'a-password-of-her-own',
            'password_confirmation' => 'a-password-of-her-own',
        ])->assertForbidden();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_somebody_who_is_already_signed_in_is_sent_home(): void
    {
        [, $token] = $this->invited();

        $this->actingAs($this->athlete())
            ->get(route('invite.show', ['token' => $token]))
            ->assertRedirect();
    }

    public function test_there_is_still_no_way_to_register(): void
    {
        // The standing promise this feature had to keep. If a route ever
        // appears that makes an account without a token, this is where
        // it should be argued for.
        foreach (['/register', '/signup', '/sign-up'] as $guess) {
            $this->get($guess)->assertNotFound();
            $this->post($guess)->assertNotFound();
        }

        $this->assertDatabaseCount('users', 0);
    }
}
