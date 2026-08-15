<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardAreasTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_one_page_carries_both_areas(): void
    {
        $this->actingAs(User::factory()->create())->get('/')
            ->assertOk()
            ->assertSee('id="tab-koerperkarte"', false)
            ->assertSee('id="panel-koerperkarte"', false)
            ->assertSee('id="panel-belastung"', false)
            // Retired areas may not leave an empty frame behind.
            ->assertDontSee('id="panel-race"', false)
            ->assertDontSee('id="panel-ernaehrung"', false);
    }

    public function test_the_retired_destinations_are_gone_rather_than_redirecting(): void
    {
        // The focus cut removed these pages outright. A 404 is the honest
        // answer; a redirect would suggest the content moved somewhere.
        foreach (['/training', '/recovery', '/longevity', '/insights', '/nutrition'] as $uri) {
            $this->actingAs(User::factory()->create())->get($uri)->assertNotFound();
        }
    }

    public function test_the_areas_stay_behind_the_login(): void
    {
        // Since the guide moved in, "/" answers a guest with 200. What
        // stayed closed is what the areas are: neither panel may be in
        // the page a request without a session gets.
        $this->get('/')
            ->assertOk()
            ->assertDontSee('id="panel-koerperkarte"', false)
            ->assertDontSee('id="panel-belastung"', false);
    }
}
