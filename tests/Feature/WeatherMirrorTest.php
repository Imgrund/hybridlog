<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Garmin\GarminData;
use App\Garmin\Weather;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesTestMirror;
use Tests\TestCase;

/**
 * The weather path from the mirror to the page.
 *
 * The unit tests pin the arithmetic. This one pins that the table the
 * fetcher writes is the table the dashboard reads, that a mirror without
 * it renders exactly as it did before, and that a session in the stream
 * carries the conditions it was worked in.
 */
class WeatherMirrorTest extends TestCase
{
    use RefreshDatabase, UsesTestMirror;

    /** @param  array<string, mixed>  $extra */
    private function hour(string $ts, float $temp, float $dewpoint, array $extra = []): array
    {
        return array_merge([
            'ts_local' => $ts,
            'date' => substr($ts, 0, 10),
            'temperature_c' => $temp,
            'apparent_c' => $temp + 1.0,
            'relative_humidity' => 70,
            'dewpoint_c' => $dewpoint,
            'wind_speed_kmh' => 5.0,
            'precipitation_mm' => 0.0,
            'uv_index' => 0.0,
            'surface_pressure_hpa' => 1_002.0,
            'cloud_cover' => 30,
            'latitude' => 52.5200,
            'longitude' => 13.4050,
            'source' => 'archive',
            'fetched_at' => '2026-08-01T09:00:00+02:00',
        ], $extra);
    }

    public function test_the_dashboard_reads_the_table_the_fetcher_writes(): void
    {
        // Through the real fetcher/schema.sql, so a column renamed there
        // fails here rather than in production.
        $this->seedMirror('weather_hourly', [
            $this->hour(now()->subDay()->toDateString().' 22:00:00', 24.0, 18.0),
        ]);

        $hours = app(Weather::class)->hours(7);

        $this->assertCount(1, $hours);
        $this->assertSame(18.0, (float) $hours->first()->dewpoint_c);
        $this->assertSame('archive', $hours->first()->source);
        $this->assertTrue(app(Weather::class)->configured());
    }

    public function test_a_mirror_without_the_weather_table_is_still_a_working_mirror(): void
    {
        // An installation that has not run the fetcher since this landed.
        // The dashboard must not care.
        $this->useTestMirror();
        $this->mirror()->statement('create table days (date text primary key, steps integer)');

        $this->assertTrue(app(GarminData::class)->weather()->isEmpty());
        $this->assertFalse(app(Weather::class)->configured());
    }

    /**
     * Twelve circuit sessions of the same length, half of them in heat, over sixteen
     * days. Only the heart rate differs, which is the shape the session
     * split is meant to surface. The days run longer than the sessions
     * because the fluid split asks for a fortnight, and they carry
     * Garmin's own hydration goal so the outlook can name what a day like
     * the one ahead has cost before.
     *
     * @param  int  $gap  beats between the warm and the cool half
     */
    private function seedCircuitSessionsAndDays(int $gap = 12): void
    {
        $activities = [];
        $days = [];
        $hours = [];
        foreach (range(1, 16) as $back) {
            $date = now()->subDays($back)->toDateString();
            $hot = $back % 2 === 1;
            if ($back <= 12) {
                $activities[] = [
                    'id' => $back,
                    'date' => $date,
                    'start_local' => $date.' 18:00:00',
                    'type_key' => 'hiit',
                    'name' => 'Circuit',
                    'duration_s' => 3_600,
                    'distance_m' => null,
                    'avg_hr' => $hot ? 128 + $gap : 128,
                    'training_load' => 140,
                    'aerobic_te' => 3.4,
                    'anaerobic_te' => 2.1,
                ];
            }
            $days[] = [
                'date' => $date,
                'steps' => 9_000,
                'resting_hr' => 48,
                'stress_avg' => 30,
                'hydration_goal_ml' => $hot ? 3_600 : 2_900,
                'sweat_loss_ml' => $hot ? 1_100 : 800,
            ];
            foreach (range(0, 23) as $h) {
                $hours[] = $this->hour(
                    sprintf('%s %02d:00:00', $date, $h),
                    $hot ? 29.0 : 11.0,
                    $hot ? 19.0 : 5.0,
                );
            }
        }
        $this->seedMirror('activities', $activities);
        $this->seedMirror('days', $days);
        $this->seedMirror('weather_hourly', $hours);
    }

    public function test_the_heat_split_names_the_gap_and_both_halves(): void
    {
        $this->seedCircuitSessionsAndDays();

        // Apparent temperature is one degree over the air in this fixture,
        // so 30 against 12 and the cut sits at 21. Twelve beats apart is
        // well over the slack the split ignores.
        $this->actingAs($this->athlete())->get('/')
            ->assertStatus(200)
            ->assertSee('Above an apparent 21.0 °C your pulse in circuit sessions sat 12 bpm higher, 140 against 128.')
            ->assertSee('Median over 6 sessions against 6 sessions, 45 to 90 min sessions only.', false);
    }

    public function test_a_heat_gap_inside_the_slack_is_reported_as_no_effect(): void
    {
        // Two beats is what a wrist sensor and a varying session produce on
        // their own, so the page has to say that plainly instead of
        // printing a difference nobody should train around.
        $this->seedCircuitSessionsAndDays(2);

        $this->actingAs($this->athlete())->get('/')
            ->assertStatus(200)
            ->assertSee('Heat has not reached your circuit sessions so far: 6 sessions above an apparent 21.0 °C and 6 sessions below it land within 2 bpm of each other.')
            ->assertDontSee('bpm higher');
    }

    public function test_a_hot_day_ahead_is_named_with_what_it_usually_costs(): void
    {
        // Sixteen ordinary days behind and a scorcher the day after
        // tomorrow, seeded as forecast hours the way the fetcher writes
        // them. The page has to place it against the mirror's own year
        // and carry the litre such days have cost.
        $this->seedCircuitSessionsAndDays();
        $hot = now()->addDays(2)->toDateString();
        $hours = [];
        foreach (range(0, 23) as $h) {
            $hours[] = $this->hour(sprintf('%s %02d:00:00', $hot, $h), 36.0, 21.0, ['source' => 'forecast']);
        }
        $this->seedMirror('weather_hourly', $hours);

        $this->actingAs($this->athlete())->get('/')
            ->assertStatus(200)
            ->assertSee('The day after sits in your warmest fifth, 37.0 °C felt and up to 37.0.')
            ->assertSee('On days like that Garmin asked for 3,600 ml.');
    }

    public function test_an_ordinary_day_ahead_says_there_is_nothing_to_plan_around(): void
    {
        $this->seedCircuitSessionsAndDays();
        $mild = now()->addDay()->toDateString();
        $hours = [];
        foreach (range(0, 23) as $h) {
            $hours[] = $this->hour(sprintf('%s %02d:00:00', $mild, $h), 19.0, 11.0, ['source' => 'forecast']);
        }
        $this->seedMirror('weather_hourly', $hours);

        $this->actingAs($this->athlete())->get('/')
            ->assertStatus(200)
            ->assertSee('Tomorrow 20.0 °C felt')
            ->assertSee('Neither day stands out against your own year, so nothing here needs planning around.');
    }

    public function test_the_hrv_card_says_whether_heat_explains_a_bad_morning(): void
    {
        // Sixteen days of weather with the mornings that followed them:
        // the resting pulse eight beats up and the HRV twelve down after
        // a warm day, both well clear of the athlete's own wobble.
        $days = [];
        $hrv = [];
        $hours = [];
        foreach (range(1, 17) as $back) {
            $date = now()->subDays(18 - $back)->toDateString();
            $hot = $back % 2 === 1;
            $afterHot = $back % 2 === 0;
            $days[] = [
                'date' => $date,
                'steps' => 9_000,
                'resting_hr' => $afterHot ? 52 : 44,
                'stress_avg' => 30,
            ];
            $hrv[] = ['date' => $date, 'last_night_avg' => $afterHot ? 60.0 : 72.0];
            if ($back <= 16) {
                foreach (range(0, 23) as $h) {
                    $hours[] = $this->hour(sprintf('%s %02d:00:00', $date, $h), $hot ? 29.0 : 11.0, $hot ? 19.0 : 5.0);
                }
            }
        }
        $this->seedMirror('days', $days);
        $this->seedMirror('hrv', $hrv);
        $this->seedMirror('weather_hourly', $hours);

        $this->actingAs($this->athlete())->get('/')
            ->assertStatus(200)
            ->assertSee('After a warm day your resting pulse ran 8 bpm higher and your HRV 12 ms lower.')
            ->assertSee('read the morning after', false);
    }

    public function test_a_mirror_where_heat_changes_nothing_says_so_on_the_hrv_card(): void
    {
        $days = [];
        $hrv = [];
        $hours = [];
        foreach (range(1, 17) as $back) {
            $date = now()->subDays(18 - $back)->toDateString();
            $hot = $back % 2 === 1;
            $days[] = ['date' => $date, 'steps' => 9_000, 'resting_hr' => 44, 'stress_avg' => 30];
            $hrv[] = ['date' => $date, 'last_night_avg' => 70.0];
            if ($back <= 16) {
                foreach (range(0, 23) as $h) {
                    $hours[] = $this->hour(sprintf('%s %02d:00:00', $date, $h), $hot ? 29.0 : 11.0, $hot ? 19.0 : 5.0);
                }
            }
        }
        $this->seedMirror('days', $days);
        $this->seedMirror('hrv', $hrv);
        $this->seedMirror('weather_hourly', $hours);

        $this->actingAs($this->athlete())->get('/')
            ->assertStatus(200)
            ->assertSee('Heat does not show in your mornings: after a warm day your resting pulse and HRV land where they land after a cool one.')
            ->assertSee('A poor morning after a hot day wants a different explanation.');
    }
}
