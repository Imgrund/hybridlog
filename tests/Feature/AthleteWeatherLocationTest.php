<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AthleteProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The weather becomes the
 * athlete's rather than the installation's.
 *
 * WEATHER_LAT/WEATHER_LON describe wherever the deployment was set up,
 * which is the right answer while an installation means one athlete and
 * the wrong one the moment it means two. These pin that a named place
 * reaches the fetcher, that an unnamed one still falls back to the
 * environment, and that two athletes end up with two skies.
 */
class AthleteWeatherLocationTest extends TestCase
{
    use RefreshDatabase;

    /** The geocoder's answer for one town, in Open-Meteo's shape. */
    private function geocoderReturns(string $name, float $lat, float $lon, string $admin1 = 'Berlin'): void
    {
        Http::fake([
            'geocoding-api.open-meteo.com/*' => Http::response([
                'results' => [[
                    'name' => $name,
                    'admin1' => $admin1,
                    'country' => 'Deutschland',
                    'latitude' => $lat,
                    'longitude' => $lon,
                ]],
            ]),
        ]);
    }

    public function test_a_town_is_saved_as_the_two_numbers_the_weather_needs(): void
    {
        $this->geocoderReturns('Berlin', 52.52437, 13.41053);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/profile/location', ['place' => 'Berlin'])
            ->assertRedirect('/profile');

        $profile = AthleteProfile::for($user)->refresh();
        $this->assertSame(52.52437, $profile->latitude);
        $this->assertSame(13.41053, $profile->longitude);
        // The resolved label, not what was typed: it is what lets the
        // athlete see the geocoder found the right Berlin.
        $this->assertSame('Berlin, Deutschland', $profile->location_name);
    }

    public function test_an_empty_answer_hands_the_athlete_back_to_the_installation(): void
    {
        $user = User::factory()->create();
        AthleteProfile::for($user)->update([
            'latitude' => 52.52437, 'longitude' => 13.41053, 'location_name' => 'Berlin',
        ]);

        $this->actingAs($user)->post('/profile/location', ['place' => '']);

        $profile = AthleteProfile::for($user)->refresh();
        $this->assertNull($profile->latitude);
        $this->assertNull($profile->longitude);
        $this->assertNull($profile->location_name);
    }

    public function test_a_name_that_resolves_to_nothing_says_so(): void
    {
        Http::fake(['geocoding-api.open-meteo.com/*' => Http::response(['results' => []])]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/profile/location', ['place' => 'Nirgendwo'])
            ->assertSessionHasErrors('place');

        $this->assertFalse(AthleteProfile::for($user)->refresh()->hasLocation());
    }

    public function test_a_geocoder_that_is_down_is_not_an_error_page(): void
    {
        // Somebody is waiting on a form submit, and a third party being
        // unreachable is not something they can act on differently from
        // a name that does not exist. Both come back as "not found".
        Http::fake(['geocoding-api.open-meteo.com/*' => Http::response('', 503)]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/profile/location', ['place' => 'Berlin'])
            ->assertRedirect('/profile')
            ->assertSessionHasErrors('place');
    }

    public function test_the_fetcher_is_told_the_athletes_own_place(): void
    {
        Process::fake();
        config(['garmin.fetch.command' => 'python fetch.py']);
        $user = $this->athlete();
        AthleteProfile::for($user)->update([
            'latitude' => 52.52437, 'longitude' => 13.41053, 'location_name' => 'Berlin',
        ]);

        $this->artisan('garmin:fetch', ['--tenant' => (string) $user->id])->assertSuccessful();

        Process::assertRan(fn ($process) => $process->command
            === 'python fetch.py --tenant='.$user->id.' --lat=52.52437 --lon=13.41053');
    }

    public function test_an_athlete_without_a_place_leaves_the_environment_in_charge(): void
    {
        // No --lat/--lon at all rather than empty ones: the fetcher reads
        // WEATHER_LAT/WEATHER_LON when it is handed nothing, which is
        // what keeps a single-athlete installation working untouched.
        Process::fake();
        config(['garmin.fetch.command' => 'python fetch.py']);
        $user = $this->athlete();

        $this->artisan('garmin:fetch', ['--tenant' => (string) $user->id])->assertSuccessful();

        Process::assertRan(fn ($process) => $process->command
            === 'python fetch.py --tenant='.$user->id);
    }

    public function test_two_athletes_get_two_skies(): void
    {
        Process::fake();
        config(['garmin.fetch.command' => 'python fetch.py']);

        $owner = $this->athlete();
        AthleteProfile::for($owner)->update([
            'latitude' => 52.52437, 'longitude' => 13.41053, 'location_name' => 'Berlin',
        ]);

        $second = User::factory()->create();
        AthleteProfile::for($second)->update([
            'latitude' => 48.13743, 'longitude' => 11.57549, 'location_name' => 'München',
        ]);

        $this->artisan('garmin:fetch', ['--tenant' => (string) $owner->id])->assertSuccessful();
        $this->artisan('garmin:fetch', ['--tenant' => (string) $second->id])->assertSuccessful();

        Process::assertRan(fn ($process) => $process->command
            === 'python fetch.py --tenant='.$owner->id.' --lat=52.52437 --lon=13.41053');
        Process::assertRan(fn ($process) => $process->command
            === 'python fetch.py --tenant='.$second->id.' --lat=48.13743 --lon=11.57549');
    }

    public function test_the_place_belongs_to_the_athlete_who_named_it(): void
    {
        $this->geocoderReturns('München', 48.13743, 11.57549, 'Bayern');
        $owner = $this->athlete();
        AthleteProfile::for($owner)->update([
            'latitude' => 52.52437, 'longitude' => 13.41053, 'location_name' => 'Berlin',
        ]);
        $second = User::factory()->create();

        $this->actingAs($second)->post('/profile/location', ['place' => 'München']);

        // The second athlete naming a place must not move the first one.
        $this->assertSame(52.52437, AthleteProfile::for($owner)->refresh()->latitude);
        $this->assertSame(48.13743, AthleteProfile::for($second)->refresh()->latitude);
    }

    public function test_the_location_form_stays_behind_the_login(): void
    {
        $this->post('/profile/location', ['place' => 'Berlin'])->assertRedirect('/login');
    }

    public function test_the_page_shows_the_stored_place_and_keeps_one_primary(): void
    {
        $user = User::factory()->create();
        AthleteProfile::for($user)->update([
            'latitude' => 52.52437, 'longitude' => 13.41053,
            'location_name' => 'Berlin, Deutschland',
        ]);

        $html = $this->actingAs($user)->get('/profile')->assertStatus(200)->getContent();

        // The field comes back filled, so saving the page again is not a
        // silent way to lose the place.
        $this->assertStringContainsString('value="Berlin, Deutschland"', $html);
        $this->assertStringContainsString('class="field mt-1"', $html);
        // One acid-lime primary owns a viewport. The language
        // above has it; this save is deliberately the quieter button.
        $this->assertSame(1, substr_count($html, 'btn-primary'));
    }

    public function test_the_page_says_when_no_place_is_set(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/profile')
            ->assertStatus(200)
            ->assertSee('No place of your own yet', false);
    }
}
