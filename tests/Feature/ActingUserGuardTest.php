<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Tenancy\ActingUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Passport;
use League\OAuth2\Server\ResourceServer;
use LogicException;
use Tests\TestCase;

/**
 * What a signed-in athlete is owed while the OAuth half of the
 * installation is broken: the dashboard.
 *
 * The api guard builds itself from the signing keys, so an installation
 * that has none throws on the way to a token nobody sent. Asked in the
 * same try as the session user, that throw used to take the session user
 * with it, and every page behind the login answered a five hundred
 * because one connector could not have worked. The quickstart met it on
 * the first visit: keys generated in the boot container never reached the
 * one that serves.
 */
class ActingUserGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An installation whose OAuth keys never arrived.
     *
     * A key that is not one fails exactly where a missing pair does, in
     * the crypt key the guard is built from, so this is the same
     * LogicException a container without the files throws. Asserted here
     * rather than assumed: a Passport release that stopped throwing would
     * otherwise leave every test below passing on nothing.
     *
     * Broken before anybody signs in, because forgetting the guards
     * afterwards would forget the session along with them.
     */
    private function withoutOauthKeys(): void
    {
        config(['passport.public_key' => 'not a key']);

        app()->forgetInstance(ResourceServer::class);
        Auth::forgetGuards();

        try {
            auth()->guard('api')->user();
            $this->fail('The api guard was expected to throw without a usable key.');
        } catch (LogicException $exception) {
            $this->assertSame('Invalid key supplied', $exception->getMessage());
        }
    }

    public function test_a_throwing_api_guard_leaves_the_session_user_standing(): void
    {
        $this->withoutOauthKeys();

        // Nobody here is admin, so there is no installation owner to land
        // on either: this resolves to the session user or to nobody.
        $athlete = User::factory()->create();

        $this->actingAs($athlete);

        $this->assertSame($athlete->id, ActingUser::require()->id);
    }

    public function test_the_dashboard_answers_while_the_keys_are_missing(): void
    {
        $this->withoutOauthKeys();

        // The five hundred as it was met: the same page, the same session,
        // and nothing wrong with either.
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('id="panel-koerperkarte"', false);
    }

    public function test_the_api_guard_is_still_asked_first(): void
    {
        // The precedence an MCP request depends on: a call that carries a
        // token acts for the token's athlete, whoever else happens to hold
        // a session in the same process.
        $this->actingAs(User::factory()->create());

        $connector = User::factory()->create();
        Passport::actingAs($connector, ['mcp:use'], 'api');

        $this->assertSame($connector->id, ActingUser::require()->id);
    }

    public function test_a_console_run_still_falls_back_to_the_owner(): void
    {
        $owner = User::factory()->admin()->create();
        User::factory()->create();

        $this->withoutOauthKeys();

        // No session and no token, which for a console run is not a
        // refusal but the local transport's one identity.
        $this->assertSame($owner->id, ActingUser::require()->id);
    }
}
