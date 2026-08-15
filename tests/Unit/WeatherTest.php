<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Garmin\GarminData;
use App\Garmin\Weather;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * The weather is context, not evidence, and these tests are mostly about
 * keeping it that way: a window says how many hours it was read over, a
 * warm/cool split refuses to appear below a usable sample, and an outlier
 * is judged against the athlete's own history rather than a fixed degree.
 */
class WeatherTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @param  array<int, array<string, float|int|null>>  $overrides  keyed by hour
     */
    private function hours(string $date, float $temp = 18.0, array $overrides = []): Collection
    {
        $rows = [];
        foreach (range(0, 23) as $hour) {
            $ts = sprintf('%s %02d:00:00', $date, $hour);
            $rows[$ts] = (object) array_merge([
                'ts_local' => $ts,
                'date' => $date,
                'temperature_c' => $temp,
                'apparent_c' => $temp - 1.0,
                'relative_humidity' => 60,
                'dewpoint_c' => $temp - 8.0,
                'wind_speed_kmh' => 6.0,
                'precipitation_mm' => 0.0,
                'uv_index' => 0.0,
                'surface_pressure_hpa' => 1_004.0,
                'cloud_cover' => 20,
            ], $overrides[$hour] ?? []);
        }

        return collect($rows);
    }

    public function test_a_window_averages_only_the_hours_it_covers(): void
    {
        $hours = $this->hours('2026-07-20', 16.0, [
            22 => ['temperature_c' => 24.0, 'dewpoint_c' => 18.0],
            23 => ['temperature_c' => 20.0, 'dewpoint_c' => 16.0],
        ]);

        $window = Weather::window($hours, '2026-07-20 22:00:00', '2026-07-21 00:00:00');

        $this->assertSame(2, $window['hours']);
        $this->assertSame(22.0, $window['temperature']);
        $this->assertSame(17.0, $window['dewpoint']);
        $this->assertSame(20.0, $window['tempMin']);
        $this->assertSame(24.0, $window['tempMax']);
    }

    public function test_the_end_of_a_window_is_exclusive(): void
    {
        // 18:00 to 19:00 is one hour of weather, not two.
        $window = Weather::window($this->hours('2026-07-20'), '2026-07-20 18:00:00', '2026-07-20 19:00:00', 1);

        $this->assertSame(1, $window['hours']);
    }

    public function test_a_window_too_thin_to_average_is_null(): void
    {
        $this->assertNull(Weather::window($this->hours('2026-07-20'), '2026-07-20 18:00:00', '2026-07-20 19:00:00'));
    }

    public function test_rain_is_summed_over_the_window_rather_than_averaged(): void
    {
        $hours = $this->hours('2026-07-20', 14.0, [
            17 => ['precipitation_mm' => 3.0],
            18 => ['precipitation_mm' => 1.4],
        ]);

        $window = Weather::window($hours, '2026-07-20 17:00:00', '2026-07-20 19:00:00');

        $this->assertSame(4.4, $window['precipitation']);
    }

    public function test_a_night_is_read_over_the_sleep_itself_not_the_calendar_night(): void
    {
        // Bed at 23:00, up at 06:00. The hot afternoon before must not
        // reach into it.
        $hours = $this->hours('2026-07-19', 30.0)
            ->merge($this->hours('2026-07-20', 15.0));
        $night = (object) [
            'date' => '2026-07-20',
            'start_local' => '2026-07-19 23:00:00',
            'end_local' => '2026-07-20 06:00:00',
        ];

        $window = Weather::forNight($night, $hours);

        $this->assertSame(7, $window['hours']);
        // One hour of the 19th at 30 C, six of the 20th at 15 C.
        $this->assertSame(17.1, $window['temperature']);
    }

    public function test_a_night_without_a_recorded_window_has_no_weather(): void
    {
        $night = (object) ['date' => '2026-07-20', 'start_local' => null, 'end_local' => null];

        $this->assertNull(Weather::forNight($night, $this->hours('2026-07-20')));
    }

    public function test_a_session_shorter_than_an_hour_still_gets_its_hour(): void
    {
        $hours = $this->hours('2026-07-20', 12.0, [18 => ['temperature_c' => 29.0, 'apparent_c' => 31.0]]);
        $session = (object) ['date' => '2026-07-20', 'start_local' => '2026-07-20 18:10:00', 'duration_s' => 2_700];

        $window = Weather::forSession($session, $hours);

        $this->assertSame(1, $window['hours']);
        $this->assertSame(29.0, $window['temperature']);
        $this->assertSame(31.0, $window['apparent']);
    }

    public function test_the_day_window_stops_before_the_night_that_follows_it(): void
    {
        $hours = $this->hours('2026-07-20', 28.0, [
            2 => ['temperature_c' => 12.0],
            22 => ['temperature_c' => 12.0],
        ]);

        $window = Weather::forDay('2026-07-20', $hours);

        $this->assertSame(12, $window['hours']);
        $this->assertSame(28.0, $window['temperature']);
    }

    public function test_an_outlier_is_judged_against_the_athletes_own_history(): void
    {
        $sample = [10.0, 11.0, 12.0, 13.0, 14.0, 15.0, 16.0, 17.0, 18.0, 19.0];

        $this->assertSame('high', Weather::outlier(18.5, $sample, 10));
        $this->assertSame('low', Weather::outlier(10.5, $sample, 10));
        $this->assertNull(Weather::outlier(14.0, $sample, 10));
    }

    public function test_nothing_is_an_outlier_before_there_is_a_history(): void
    {
        $this->assertNull(Weather::outlier(28.0, [12.0, 13.0, 14.0], 10));
    }

    public function test_a_contrast_splits_at_the_median_and_reports_both_sides(): void
    {
        // Dew point against deep sleep: four cool nights with good deep
        // sleep, four warm ones with poor.
        $pairs = [[8.0, 90.0], [9.0, 100.0], [10.0, 80.0], [11.0, 90.0],
            [17.0, 50.0], [18.0, 60.0], [19.0, 40.0], [20.0, 50.0]];

        $contrast = Weather::contrast($pairs, 8);

        $this->assertSame(4, $contrast['warmN']);
        $this->assertSame(4, $contrast['coolN']);
        $this->assertSame(14.0, $contrast['cut']);
        $this->assertSame(50.0, $contrast['warm']);
        $this->assertSame(90.0, $contrast['cool']);
        $this->assertSame(-40.0, $contrast['difference']);
    }

    public function test_a_contrast_below_the_minimum_sample_is_not_reported(): void
    {
        $pairs = [[8.0, 90.0], [18.0, 50.0]];

        $this->assertNull(Weather::contrast($pairs, 14));
    }

    public function test_a_contrast_with_one_empty_half_is_not_a_contrast(): void
    {
        // Every night at the same dew point: the split has nothing to
        // compare, and reporting a difference of zero would suggest it
        // looked.
        $pairs = array_fill(0, 14, [12.0, 80.0]);

        $this->assertNull(Weather::contrast($pairs, 14));
    }

    public function test_deep_sleep_is_only_split_once_enough_nights_carry_weather(): void
    {
        $weather = new Weather(Mockery::mock(GarminData::class));
        $hours = new Collection;
        $sleep = new Collection;
        foreach (range(1, 20) as $i) {
            $date = sprintf('2026-07-%02d', $i);
            // Odd nights muggy and short on deep sleep, even nights dry.
            // The window stays inside its own date so each night reads
            // the hours meant for it and not the neighbouring night's.
            $muggy = $i % 2 === 1;
            $hours = $hours->merge($this->hours($date, $muggy ? 24.0 : 14.0));
            $sleep->push((object) [
                'date' => $date,
                'start_local' => $date.' 00:30:00',
                'end_local' => $date.' 06:00:00',
                'deep_s' => ($muggy ? 45 : 90) * 60,
            ]);
        }

        $result = $weather->deepSleepByDewpoint($sleep, $hours->keyBy('ts_local'));

        $this->assertSame('min', $result['unit']);
        $this->assertSame(45.0, $result['contrast']['warm']);
        $this->assertSame(90.0, $result['contrast']['cool']);
        $this->assertSame(20, $result['contrast']['warmN'] + $result['contrast']['coolN']);
    }

    /** @param  array<string, mixed>  $extra */
    private function wod(string $date, int $minutes, int $hr, array $extra = []): object
    {
        return (object) array_merge([
            'date' => $date,
            'type_key' => 'hiit',
            'start_local' => $date.' 18:00:00',
            'duration_s' => $minutes * 60,
            'avg_hr' => $hr,
            'distance_m' => null,
            'training_load' => 140,
        ], $extra);
    }

    public function test_a_clear_difference_in_wod_pulse_is_reported_as_one(): void
    {
        $weather = new Weather(Mockery::mock(GarminData::class));
        $hours = new Collection;
        $activities = new Collection;
        foreach (range(1, 12) as $i) {
            $date = sprintf('2026-07-%02d', $i);
            $warm = $i % 2 === 1;
            $hours = $hours->merge($this->hours($date, $warm ? 30.0 : 12.0));
            $activities->push($this->wod($date, 60, $warm ? 140 : 128));
        }

        $result = $weather->sessionStrainByHeat($activities, $hours->keyBy('ts_local'));

        $this->assertTrue($result['material']);
        $this->assertSame('bpm', $result['unit']);
        $this->assertSame(140.0, $result['contrast']['warm']);
        $this->assertSame(128.0, $result['contrast']['cool']);
        $this->assertSame(12.0, $result['contrast']['difference']);
    }

    public function test_a_wod_difference_too_small_to_act_on_is_flagged_as_such(): void
    {
        // Two beats apart, which a wrist sensor and a varying session produce
        // on their own. The split still reports, so the card can say that
        // heat does not seem to reach these sessions, but it must not
        // present the two beats as an effect.
        $weather = new Weather(Mockery::mock(GarminData::class));
        $hours = new Collection;
        $activities = new Collection;
        foreach (range(1, 12) as $i) {
            $date = sprintf('2026-07-%02d', $i);
            $warm = $i % 2 === 1;
            $hours = $hours->merge($this->hours($date, $warm ? 30.0 : 12.0));
            $activities->push($this->wod($date, 60, $warm ? 126 : 124));
        }

        $result = $weather->sessionStrainByHeat($activities, $hours->keyBy('ts_local'));

        $this->assertFalse($result['material']);
        $this->assertSame(2.0, $result['contrast']['difference']);
    }

    public function test_a_wod_outside_the_duration_band_does_not_take_part(): void
    {
        // Twelve sessions, but the first two are a twenty minute sprint and
        // a two hour grind. Length would decide the comparison before the
        // weather got a word in, so they stay out and the rest is one
        // session short of the floor.
        $weather = new Weather(Mockery::mock(GarminData::class));
        $hours = new Collection;
        $activities = new Collection;
        foreach (range(1, 11) as $i) {
            $date = sprintf('2026-07-%02d', $i);
            $warm = $i % 2 === 1;
            $hours = $hours->merge($this->hours($date, $warm ? 30.0 : 12.0));
            $activities->push($this->wod($date, match ($i) {
                1 => 20, 2 => 120, default => 60
            }, $warm ? 140 : 128));
        }

        $this->assertNull($weather->sessionStrainByHeat($activities, $hours->keyBy('ts_local')));
    }

    public function test_only_wods_answer_the_wod_question(): void
    {
        // A dozen runs, none of them a circuit session. The split has no business
        // borrowing them to reach its sample.
        $weather = new Weather(Mockery::mock(GarminData::class));
        $hours = new Collection;
        $activities = new Collection;
        foreach (range(1, 12) as $i) {
            $date = sprintf('2026-07-%02d', $i);
            $hours = $hours->merge($this->hours($date, $i % 2 === 1 ? 30.0 : 12.0));
            $activities->push($this->wod($date, 60, 150, ['type_key' => 'running', 'distance_m' => 8_000.0]));
        }

        $this->assertNull($weather->sessionStrainByHeat($activities, $hours->keyBy('ts_local')));
    }

    /** @param  array<string, mixed>  $extra */
    private function day(string $date, ?int $goal, ?int $sweat): object
    {
        return (object) ['date' => $date, 'hydration_goal_ml' => $goal, 'sweat_loss_ml' => $sweat];
    }

    public function test_the_fluid_split_reports_the_goal_and_the_sweat_apart(): void
    {
        $weather = new Weather(Mockery::mock(GarminData::class));
        $hours = new Collection;
        $days = new Collection;
        foreach (range(1, 16) as $i) {
            $date = sprintf('2026-07-%02d', $i);
            $warm = $i % 2 === 1;
            $hours = $hours->merge($this->hours($date, $warm ? 30.0 : 12.0));
            $days->push($this->day($date, $warm ? 3_600 : 2_900, $warm ? 1_100 : 800));
        }

        $result = $weather->fluidByHeat($days, $hours->keyBy('ts_local'));

        $this->assertSame(3_600.0, $result['goal']['warm']);
        $this->assertSame(2_900.0, $result['goal']['cool']);
    }

    public function test_a_day_without_a_sweat_estimate_still_counts_towards_the_goal(): void
    {
        // Garmin only estimates sweat for days that held a session, so a
        // day without one still has to carry its hydration goal.
        $weather = new Weather(Mockery::mock(GarminData::class));
        $hours = new Collection;
        $days = new Collection;
        foreach (range(1, 16) as $i) {
            $date = sprintf('2026-07-%02d', $i);
            $warm = $i % 2 === 1;
            $hours = $hours->merge($this->hours($date, $warm ? 30.0 : 12.0));
            $days->push($this->day($date, $warm ? 3_600 : 2_900, null));
        }

        $result = $weather->fluidByHeat($days, $hours->keyBy('ts_local'));

        $this->assertSame(700.0, $result['goal']['difference']);
    }

    public function test_no_fluid_figures_at_all_means_no_split(): void
    {
        $weather = new Weather(Mockery::mock(GarminData::class));
        $hours = $this->hours('2026-07-01', 20.0);
        $days = collect([$this->day('2026-07-01', null, null)]);

        $this->assertNull($weather->fluidByHeat($days, $hours->keyBy('ts_local')));
    }

    /**
     * A stretch of ordinary days behind, plus however many ahead the
     * forecast reaches, so the outlook has a year to be judged against.
     */
    private function outlookHours(float $ahead1, float $ahead2): Collection
    {
        Carbon::setTestNow('2026-07-20 09:00:00');
        $hours = new Collection;
        foreach (range(1, 20) as $back) {
            // A spread rather than one repeated temperature: against a
            // history that never moves, every day ahead is an outlier.
            $hours = $hours->merge($this->hours(now()->subDays($back)->toDateString(), 10.0 + $back));
        }
        $hours = $hours->merge($this->hours(now()->addDay()->toDateString(), $ahead1));

        return $hours->merge($this->hours(now()->addDays(2)->toDateString(), $ahead2))->keyBy('ts_local');
    }

    public function test_a_day_ahead_is_placed_against_the_days_behind(): void
    {
        $weather = new Weather(Mockery::mock(GarminData::class));

        // Twenty days spread over 11 to 30 C, then a mild tomorrow and a
        // hot day after.
        $ahead = $weather->outlook($this->outlookHours(19.0, 34.0));

        $this->assertCount(2, $ahead);
        $this->assertSame(1, $ahead[0]['offset']);
        $this->assertNull($ahead[0]['outlier']);
        $this->assertSame('high', $ahead[1]['outlier']);
        // Apparent runs a degree under the air in this fixture.
        $this->assertSame(33.0, $ahead[1]['apparent']);
        $this->assertSame(33.0, $ahead[1]['peak']);
    }

    public function test_the_outlook_says_nothing_about_days_the_forecast_does_not_reach(): void
    {
        Carbon::setTestNow('2026-07-20 09:00:00');
        $weather = new Weather(Mockery::mock(GarminData::class));
        $hours = new Collection;
        foreach (range(1, 20) as $back) {
            $hours = $hours->merge($this->hours(now()->subDays($back)->toDateString(), 18.0));
        }

        $this->assertSame([], $weather->outlook($hours->keyBy('ts_local')));
    }

    public function test_without_a_history_no_day_ahead_is_called_unusual(): void
    {
        // Three days behind is nothing to judge a 34 C day against, so the
        // outlook still reports the temperature but claims nothing about
        // where it sits.
        Carbon::setTestNow('2026-07-20 09:00:00');
        $weather = new Weather(Mockery::mock(GarminData::class));
        $hours = new Collection;
        foreach (range(1, 3) as $back) {
            $hours = $hours->merge($this->hours(now()->subDays($back)->toDateString(), 18.0));
        }
        $hours = $hours->merge($this->hours(now()->addDay()->toDateString(), 34.0));

        $ahead = $weather->outlook($hours->keyBy('ts_local'));

        $this->assertCount(1, $ahead);
        $this->assertNull($ahead[0]['outlier']);
    }

    public function test_a_window_reports_the_peak_it_felt_like_not_only_the_mean(): void
    {
        // A mild day with one brutal afternoon hour. The mean would talk
        // an athlete out of moving the session; the peak is the reason to.
        $hours = $this->hours('2026-07-20', 20.0, [15 => ['apparent_c' => 36.0]]);

        $window = Weather::forDay('2026-07-20', $hours);

        // Eleven hours at 19 felt and one at 36, over the 08:00 to 20:00
        // window: a mean of 20.4 against a peak of 36.
        $this->assertSame(20.4, $window['apparent']);
        $this->assertSame(36.0, $window['apparentMax']);
    }

    /**
     * Sixteen days of weather with the morning figures of the day after
     * each one, so the split has something to read across the offset.
     *
     * @return array{days: Collection, hrv: Collection, hours: Collection}
     */
    private function mornings(int $rhrWarm, int $rhrCool, float $hrvWarm, float $hrvCool): array
    {
        $hours = new Collection;
        $days = new Collection;
        $hrv = new Collection;
        foreach (range(1, 17) as $i) {
            $date = sprintf('2026-07-%02d', $i);
            // The morning belongs to the day before it, so day 1 being warm
            // is read on day 2's figures.
            $afterWarm = $i % 2 === 0;
            if ($i <= 16) {
                $hours = $hours->merge($this->hours($date, $i % 2 === 1 ? 30.0 : 12.0));
            }
            $days->push((object) [
                'date' => $date,
                'resting_hr' => $afterWarm ? $rhrWarm : $rhrCool,
                'hydration_goal_ml' => null,
                'sweat_loss_ml' => null,
            ]);
            $hrv->push((object) ['date' => $date, 'last_night_avg' => $afterWarm ? $hrvWarm : $hrvCool]);
        }

        return ['days' => $days, 'hrv' => $hrv, 'hours' => $hours->keyBy('ts_local')];
    }

    public function test_a_warm_day_that_moves_the_morning_is_reported_as_moving_it(): void
    {
        $weather = new Weather(Mockery::mock(GarminData::class));
        $f = $this->mornings(52, 46, 60.0, 72.0);

        $result = $weather->recoveryByHeat($f['days'], $f['hrv'], $f['hours']);

        $this->assertTrue($result['rhr']['material']);
        $this->assertSame(6.0, $result['rhr']['contrast']['difference']);
        $this->assertTrue($result['hrv']['material']);
        $this->assertSame(-12.0, $result['hrv']['contrast']['difference']);
    }

    public function test_a_morning_that_barely_moves_is_not_called_an_effect(): void
    {
        // One beat on a resting pulse of 44 and one millisecond on an HRV
        // of 70: both under a twentieth of the athlete's own level, which
        // is where the day-to-day wobble already lives.
        $weather = new Weather(Mockery::mock(GarminData::class));
        $f = $this->mornings(45, 44, 71.0, 70.0);

        $result = $weather->recoveryByHeat($f['days'], $f['hrv'], $f['hours']);

        $this->assertFalse($result['rhr']['material']);
        $this->assertFalse($result['hrv']['material']);
    }

    public function test_the_morning_split_needs_a_fortnight_like_the_others(): void
    {
        $weather = new Weather(Mockery::mock(GarminData::class));
        $hours = new Collection;
        $days = new Collection;
        foreach (range(1, 6) as $i) {
            $date = sprintf('2026-07-%02d', $i);
            $hours = $hours->merge($this->hours($date, $i % 2 === 1 ? 30.0 : 12.0));
            $days->push((object) ['date' => $date, 'resting_hr' => 44 + $i]);
        }

        $this->assertNull($weather->recoveryByHeat($days, new Collection, $hours->keyBy('ts_local')));
    }
}
