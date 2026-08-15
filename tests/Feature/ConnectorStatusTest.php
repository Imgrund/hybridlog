<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConnectorStatusTest extends TestCase
{
    use RefreshDatabase;

    private function accessToken(User $user, bool $revoked = false, string $expires = '+1 hour'): string
    {
        $id = Str::random(40);
        DB::table('oauth_access_tokens')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'client_id' => (string) Str::uuid(),
            'revoked' => $revoked,
            'created_at' => now(),
            'updated_at' => now(),
            'expires_at' => now()->modify($expires),
        ]);

        return $id;
    }

    private function refreshToken(string $accessTokenId, bool $revoked = false, string $expires = '+30 days'): void
    {
        DB::table('oauth_refresh_tokens')->insert([
            'id' => Str::random(40),
            'access_token_id' => $accessTokenId,
            'revoked' => $revoked,
            'expires_at' => now()->modify($expires),
        ]);
    }

    public function test_header_offers_connecting_when_no_token_exists(): void
    {
        $this->actingAs(User::factory()->create())->get('/')
            ->assertSee('Connect AI')
            ->assertDontSee('AI connected');
    }

    public function test_header_shows_connected_with_a_valid_access_token(): void
    {
        $user = User::factory()->create();
        $this->accessToken($user);

        $this->actingAs($user)->get('/')
            ->assertSee('AI connected');

        $this->actingAs($user)->get('/connect')
            ->assertSee('Connected')
            ->assertSee('Disconnect');
    }

    public function test_somebody_elses_token_does_not_show_me_connected(): void
    {
        $this->accessToken(User::factory()->create());

        $this->actingAs(User::factory()->create())->get('/')
            ->assertSee('Connect AI')
            ->assertDontSee('AI connected');
    }

    public function test_an_expired_access_token_still_counts_via_its_refresh_token(): void
    {
        $user = User::factory()->create();
        $id = $this->accessToken($user, expires: '-1 hour');
        $this->refreshToken($id);

        $this->actingAs($user)->get('/')
            ->assertSee('AI connected');
    }

    public function test_revoked_and_expired_tokens_do_not_count(): void
    {
        $user = User::factory()->create();
        $id = $this->accessToken($user, revoked: true);
        $this->refreshToken($id, expires: '-1 day');

        $this->actingAs($user)->get('/')
            ->assertDontSee('AI connected');

        $this->actingAs($user)->get('/connect')
            ->assertDontSee('Disconnect');
    }

    public function test_disconnect_revokes_every_own_token_and_nobody_elses(): void
    {
        $user = User::factory()->create();
        $id = $this->accessToken($user);
        $this->refreshToken($id);

        $other = User::factory()->create();
        $keptId = $this->accessToken($other);
        $this->refreshToken($keptId);

        $this->actingAs($user)
            ->post('/connect/disconnect')
            ->assertRedirect('/connect');

        // The disconnecting user's tokens are dead, both kinds ...
        $this->assertSame(0, DB::table('oauth_access_tokens')
            ->where('user_id', $user->id)->where('revoked', false)->count());
        $this->assertSame(0, DB::table('oauth_refresh_tokens')
            ->where('access_token_id', $id)->where('revoked', false)->count());

        // ... while the other user's connector still works.
        $this->assertSame(1, DB::table('oauth_access_tokens')
            ->where('user_id', $other->id)->where('revoked', false)->count());
        $this->assertSame(1, DB::table('oauth_refresh_tokens')
            ->where('access_token_id', $keptId)->where('revoked', false)->count());

        $this->actingAs($user)->get('/')
            ->assertSee('Connect AI')
            ->assertDontSee('AI connected');
        $this->actingAs($other)->get('/')
            ->assertSee('AI connected');
    }
}
