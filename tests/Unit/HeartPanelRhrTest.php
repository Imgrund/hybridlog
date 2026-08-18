<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Garmin\Insights;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The heart panel's resting-HR averages against Garmin's provisional
 * on-device reading. The early value for today can sit far above where
 * it settles by evening, and a single such spike moves the 7-day mean
 * most of the way to the warning threshold; the panel must read the
 * revised-away artefact out, and must not read out a settled value.
 */
class HeartPanelRhrTest extends TestCase
{
    private function systems(Collection $days): array
    {
        return (new Insights)->systems(
            $days,
            collect(),
            collect(),
            null,
            ['value' => null, 'source' => 'computed', 'acute' => null, 'chronic' => null],
            ['bedtimeSdMin' => null, 'wakeSdMin' => null, 'avgDurationH' => null, 'avgScore' => null],
            null,
        );
    }

    private function dayRow(int $daysAgo, float $restingHr, ?float $minHr): object
    {
        return (object) [
            'date' => now()->subDays($daysAgo)->toDateString(),
            'resting_hr' => $restingHr,
            'min_hr' => $minHr,
            'vo2max_running' => null,
            'spo2_avg' => null,
            'spo2_lowest' => null,
        ];
    }

    /**
     * 22 quiet days at 50, six mildly raised at 52, and today at
     * $todayRestingHr: with today counted at 68 the 7-day mean crosses
     * the 2.5 bpm warning threshold, without it the drift stays benign.
     */
    private function series(float $todayRestingHr, ?float $todayMinHr): Collection
    {
        $rows = collect();
        for ($i = 28; $i >= 7; $i--) {
            $rows->push($this->dayRow($i, 50.0, 47.0));
        }
        for ($i = 6; $i >= 1; $i--) {
            $rows->push($this->dayRow($i, 52.0, 49.0));
        }
        $rows->push($this->dayRow(0, $todayRestingHr, $todayMinHr));

        return $rows;
    }

    public function test_a_provisional_reading_today_does_not_tip_the_heart_panel(): void
    {
        // 68 over a floor of 45 is the provisional artefact.
        $heart = $this->systems($this->series(68.0, 45.0))['heart'];

        $this->assertSame('good', $heart['status']);
    }

    public function test_a_settled_reading_today_counts_as_always(): void
    {
        // The same 68, but near the day's own floor (the whole night ran
        // high): a real reading, and with it the mean crosses the
        // threshold it should cross.
        $heart = $this->systems($this->series(68.0, 59.0))['heart'];

        $this->assertSame('warning', $heart['status']);
    }
}
