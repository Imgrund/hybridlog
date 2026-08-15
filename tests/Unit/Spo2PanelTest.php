<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Garmin\Insights;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The respiratory system panel around nightly SpO2: while the watch
 * sensor is off (all values null) it explains how to enable it; once
 * values exist it shows last night's average and low instead.
 */
class Spo2PanelTest extends TestCase
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

    private function dayRow(int $daysAgo, ?float $spo2Avg = null, ?float $spo2Lowest = null): object
    {
        return (object) [
            'date' => now()->subDays($daysAgo)->toDateString(),
            'resting_hr' => 50,
            'vo2max_running' => null,
            'spo2_avg' => $spo2Avg,
            'spo2_lowest' => $spo2Lowest,
        ];
    }

    public function test_without_spo2_data_the_panel_carries_the_enable_help(): void
    {
        $days = collect([$this->dayRow(1), $this->dayRow(0)]);

        $lungs = $this->systems($days)['lungs'];

        $this->assertNotNull($lungs['help']);
        $this->assertStringContainsString('pulse oximeter', $lungs['help']);
        $this->assertStringContainsString('During Sleep', $lungs['help']);
        $this->assertSame([], array_filter($lungs['facts'], fn ($f) => str_contains($f['label'], 'SpO2')));
    }

    public function test_with_spo2_values_the_panel_shows_them_and_drops_the_help(): void
    {
        $days = collect([$this->dayRow(1, 96.0, 92.0), $this->dayRow(0, 94.6, 89.0)]);

        $lungs = $this->systems($days)['lungs'];

        $this->assertNull($lungs['help']);
        $spo2 = array_values(array_filter($lungs['facts'], fn ($f) => $f['label'] === 'SpO2 last night'));
        $this->assertCount(1, $spo2);
        $this->assertSame('Ø 95 % · low 89 %', $spo2[0]['value']);
    }

    public function test_values_older_than_30_days_do_not_count_as_present(): void
    {
        $days = collect([$this->dayRow(40, 95.0, 90.0), $this->dayRow(0)]);

        $lungs = $this->systems($days)['lungs'];

        $this->assertNotNull($lungs['help']);
    }

    public function test_rows_without_the_columns_behave_like_no_data(): void
    {
        // The live mirror gains the columns only with the first fetch of
        // the new script; until then the rows simply lack the properties.
        $days = collect([(object) [
            'date' => now()->toDateString(),
            'resting_hr' => 50,
            'vo2max_running' => null,
        ]]);

        $lungs = $this->systems($days)['lungs'];

        $this->assertNotNull($lungs['help']);
    }
}
