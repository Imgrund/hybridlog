<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Garmin\Insights;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The illness rule on constructed series: baseline is the median over
 * 30 days excluding the last two, at least two of three criteria with
 * resting HR mandatory, warning at two and serious at three.
 */
class IllnessWarningTest extends TestCase
{
    private Insights $insights;

    protected function setUp(): void
    {
        parent::setUp();
        $this->insights = new Insights;
    }

    /** 30 baseline days at $base, today at $today. */
    private function days(float $base, ?float $today): Collection
    {
        $rows = collect();
        for ($i = 31; $i >= 2; $i--) {
            $rows->push((object) ['date' => now()->subDays($i)->toDateString(), 'resting_hr' => $base]);
        }
        $rows->push((object) ['date' => now()->toDateString(), 'resting_hr' => $today]);

        return $rows;
    }

    private function sleep(float $base, ?float $today): Collection
    {
        $rows = collect();
        for ($i = 31; $i >= 2; $i--) {
            $rows->push((object) ['date' => now()->subDays($i)->toDateString(), 'respiration_avg' => $base]);
        }
        $rows->push((object) ['date' => now()->toDateString(), 'respiration_avg' => $today]);

        return $rows;
    }

    private function hrv(?float $lastNight, float $bandLow = 45.0, float $weekly = 55.0): object
    {
        return (object) [
            'last_night_avg' => $lastNight,
            'baseline_balanced_low' => $bandLow,
            'weekly_avg' => $weekly,
        ];
    }

    public function test_stable_values_do_not_fire(): void
    {
        $result = $this->insights->illnessWarning(
            $this->days(50, 51), $this->sleep(14, 14.5), $this->hrv(55.0)
        );

        $this->assertNull($result);
    }

    public function test_resting_hr_plus_respiration_fire_a_warning(): void
    {
        $result = $this->insights->illnessWarning(
            $this->days(50, 56), $this->sleep(14, 16.5), $this->hrv(55.0)
        );

        $this->assertNotNull($result);
        $this->assertSame('warning', $result['status']);
        $this->assertFalse($result['criteria']['hrv']);
        $this->assertStringContainsString('Resting heart rate +6 bpm', $result['message']);
        $this->assertStringContainsString('breathing rate +2.5', $result['message']);
    }

    public function test_all_three_criteria_fire_serious(): void
    {
        $result = $this->insights->illnessWarning(
            $this->days(50, 56), $this->sleep(14, 16.5), $this->hrv(40.0, bandLow: 45.0)
        );

        $this->assertNotNull($result);
        $this->assertSame('serious', $result['status']);
        $this->assertStringContainsString('HRV last night below the normal band', $result['message']);
    }

    public function test_without_the_resting_hr_criterion_nothing_fires(): void
    {
        // Respiration and HRV clearly off, resting HR at baseline: the
        // mandatory criterion is missing, so the rule stays silent.
        $result = $this->insights->illnessWarning(
            $this->days(50, 50), $this->sleep(14, 17.0), $this->hrv(38.0)
        );

        $this->assertNull($result);
    }

    public function test_resting_hr_alone_is_not_enough(): void
    {
        $result = $this->insights->illnessWarning(
            $this->days(50, 56), $this->sleep(14, 14.2), $this->hrv(55.0)
        );

        $this->assertNull($result);
    }

    public function test_the_hrv_criterion_also_fires_ten_percent_under_the_weekly_average(): void
    {
        // 44 against a weekly average of 50 is 12 % under it while still
        // inside the balanced band (low edge 40).
        $result = $this->insights->illnessWarning(
            $this->days(50, 56), $this->sleep(14, 14.2), $this->hrv(44.0, bandLow: 40.0, weekly: 50.0)
        );

        $this->assertNotNull($result);
        $this->assertSame('warning', $result['status']);
        $this->assertStringContainsString('below the weekly average', $result['message']);
    }

    public function test_a_provisional_resting_hr_today_does_not_fire(): void
    {
        // Today's reading sits 17 bpm above the day's own HR floor: that
        // is Garmin's provisional on-device value, not an onset. With no
        // final reading to fall back on, the rule stays silent instead
        // of reporting an illness pattern built on a number that will be
        // revised away by evening.
        $days = $this->days(50, null);
        $days->push((object) ['date' => now()->toDateString(), 'resting_hr' => 62.0, 'min_hr' => 45.0]);

        $result = $this->insights->illnessWarning(
            $days, $this->sleep(14, 16.5), $this->hrv(38.0)
        );

        $this->assertNull($result);
    }

    public function test_yesterdays_final_reading_takes_over_from_a_provisional_today(): void
    {
        // Yesterday is finished and genuinely elevated; today is a
        // provisional spike. The rule reads yesterday and fires on the
        // real deviation, not on the artefact.
        $days = $this->days(50, null);
        $days->push((object) ['date' => now()->subDay()->toDateString(), 'resting_hr' => 56.0, 'min_hr' => 54.0]);
        $days->push((object) ['date' => now()->toDateString(), 'resting_hr' => 62.0, 'min_hr' => 45.0]);

        $result = $this->insights->illnessWarning(
            $days, $this->sleep(14, 16.5), $this->hrv(55.0)
        );

        $this->assertNotNull($result);
        $this->assertSame('warning', $result['status']);
        $this->assertStringContainsString('Resting heart rate +6 bpm', $result['message']);
    }

    public function test_todays_resting_hr_near_its_floor_still_counts(): void
    {
        // Elevated against the baseline but close to the day's floor:
        // that is a settled reading, and the guard must not eat it.
        $days = $this->days(50, null);
        $days->push((object) ['date' => now()->toDateString(), 'resting_hr' => 56.0, 'min_hr' => 53.0]);

        $result = $this->insights->illnessWarning(
            $days, $this->sleep(14, 16.5), $this->hrv(55.0)
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('Resting heart rate +6 bpm', $result['message']);
    }

    public function test_a_thin_baseline_stays_silent(): void
    {
        // Only 5 baseline days: no median worth the name, no verdict.
        $days = collect();
        for ($i = 6; $i >= 2; $i--) {
            $days->push((object) ['date' => now()->subDays($i)->toDateString(), 'resting_hr' => 50.0]);
        }
        $days->push((object) ['date' => now()->toDateString(), 'resting_hr' => 60.0]);

        $result = $this->insights->illnessWarning(
            $days, $this->sleep(14, 17.0), $this->hrv(38.0)
        );

        $this->assertNull($result);
    }

    public function test_the_last_two_days_do_not_drag_the_baseline(): void
    {
        // Yesterday already elevated: it must not raise the baseline and
        // thereby hide today's deviation.
        $days = $this->days(50, 56);
        $days->push((object) ['date' => now()->subDay()->toDateString(), 'resting_hr' => 57.0]);

        $result = $this->insights->illnessWarning(
            $days, $this->sleep(14, 16.5), $this->hrv(55.0)
        );

        $this->assertNotNull($result);
        $this->assertStringContainsString('Resting heart rate +6 bpm', $result['message']);
    }

    public function test_the_pattern_hangs_into_the_affected_systems(): void
    {
        $systems = [
            'heart' => ['status' => 'good', 'recommendation' => 'alt', 'facts' => []],
            'lungs' => ['status' => 'good', 'recommendation' => 'alt', 'facts' => []],
            'core' => ['status' => 'critical', 'recommendation' => 'bleibt', 'facts' => []],
        ];
        $illness = $this->insights->illnessWarning(
            $this->days(50, 56), $this->sleep(14, 16.5), $this->hrv(40.0)
        );

        $result = $this->insights->applyIllnessWarning($systems, $illness);

        $this->assertSame('serious', $result['heart']['status']);
        $this->assertSame('serious', $result['lungs']['status']);
        $this->assertStringContainsString('not a diagnosis', $result['heart']['recommendation']);
        $this->assertSame('Illness pattern', $result['heart']['facts'][0]['label']);
        // A system already above the pattern's status keeps its own finding.
        $this->assertSame('critical', $result['core']['status']);
        $this->assertSame('bleibt', $result['core']['recommendation']);
    }

    public function test_no_pattern_leaves_the_systems_untouched(): void
    {
        $systems = ['heart' => ['status' => 'good', 'recommendation' => 'alt', 'facts' => []]];

        $this->assertSame($systems, $this->insights->applyIllnessWarning($systems, null));
    }
}
