<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Garmin\WatchSync;
use Carbon\Carbon;
use Tests\TestCase;

class WatchSyncTest extends TestCase
{
    private function berlin(string $time): Carbon
    {
        return Carbon::parse($time, 'Europe/Berlin');
    }

    public function test_a_sync_earlier_today_reads_as_a_bare_time(): void
    {
        $sync = WatchSync::describe($this->berlin('2026-07-27 08:34'), $this->berlin('2026-07-27 10:00'));

        $this->assertSame('08:34', $sync['label']);
        $this->assertFalse($sync['stale']);
    }

    public function test_a_sync_three_hours_back_counts_as_stale(): void
    {
        $sync = WatchSync::describe($this->berlin('2026-07-27 08:34'), $this->berlin('2026-07-27 11:34'));

        $this->assertTrue($sync['stale']);
    }

    public function test_a_sync_on_an_earlier_day_carries_its_date(): void
    {
        $sync = WatchSync::describe($this->berlin('2026-07-26 21:10'), $this->berlin('2026-07-27 09:00'));

        $this->assertSame('Jul 26, 21:10', $sync['label']);
        $this->assertTrue($sync['stale']);
    }

    public function test_without_a_stamp_there_is_nothing_to_describe(): void
    {
        $this->assertNull(WatchSync::describe(null));
    }
}
