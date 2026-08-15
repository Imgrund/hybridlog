<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_get_the_guide_rather_than_the_dashboard(): void
    {
        // "/" answers both readers, so the status code no longer tells
        // them apart: what pins the door is that a request without a
        // session gets the guide and none of the dashboard's controls.
        $this->get('/')
            ->assertOk()
            ->assertSee(__('How to get going'))
            ->assertDontSee(route('fetch.now'));
    }

    public function test_the_dashboard_renders_for_an_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertStatus(200);
    }
}
