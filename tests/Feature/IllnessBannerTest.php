<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesGarminMirror;
use Tests\TestCase;

/**
 * The illness banner on the dashboard: absent on quiet data, present
 * with its body-map facts when the pattern fires. GarminData is mocked
 * wholesale so the page renders from constructed rows instead of the
 * live mirror.
 */
class IllnessBannerTest extends TestCase
{
    use FakesGarminMirror;
    use RefreshDatabase;

    public function test_quiet_data_shows_no_banner(): void
    {
        $this->mockGarmin(['rhrToday' => 51.0, 'respToday' => 14.2, 'hrvLastNight' => 55.0]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertStatus(200)
            // The methodology footer explains the rule on every load;
            // banner and body-map facts exist only while it fires.
            ->assertDontSee('Unusual pattern')
            ->assertDontSee('Illness pattern', false);
    }

    public function test_the_firing_pattern_shows_the_banner(): void
    {
        $this->mockGarmin(['rhrToday' => 56.0, 'respToday' => 16.5, 'hrvLastNight' => 40.0]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/')
            ->assertStatus(200)
            ->assertSee('Unusual pattern')
            ->assertSee('Pattern hint, not a diagnosis')
            // The body map hangs the pattern into its systems, on the
            // same page as the banner.
            ->assertSee('Illness pattern', false);
    }
}
