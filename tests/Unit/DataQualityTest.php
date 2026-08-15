<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Garmin\DataQuality;
use App\Garmin\DataStatus;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The data-basis strip exists because the mirror can be less than it looks
 * without anything failing. What is pinned here is when a flag fires and,
 * just as importantly, when it stays quiet: a strip that cries gap on a
 * normal morning is one nobody reads by the second week.
 */
class DataQualityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The cases here name one day out loud (entries are written on
     * 2026-08-01), while day() and workout() count their rows back from
     * the clock. The two agree only while the clock stands on that day,
     * so a run that crosses midnight builds a baseline holding no row
     * for the day it then asks about. Pinning the clock is what makes
     * both halves describe the same day whenever the suite runs.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-01 09:00:00'));
    }

    private function fresh(): DataStatus
    {
        return DataStatus::evaluate(Carbon::now()->toIso8601String(), null, null);
    }

    private function day(int $daysAgo, ?int $active): object
    {
        return (object) [
            'date' => Carbon::now()->subDays($daysAgo)->toDateString(),
            'calories_active' => $active,
        ];
    }

    private function workout(int $daysAgo, string $startsAt = '17:00'): object
    {
        $date = Carbon::now()->subDays($daysAgo);

        return (object) [
            'date' => $date->toDateString(),
            'start_local' => $date->format('Y-m-d').' '.$startsAt.':00',
        ];
    }

    /** @param  Collection<int, object>|null  $days */
    private function evaluate(
        ?Collection $days = null,
        ?Collection $activities = null,
        ?Carbon $watchSyncedAt = null,
        ?Carbon $now = null,
        ?DataStatus $status = null,
    ): array {
        return (new DataQuality)->evaluate(
            $days ?? new Collection,
            $activities ?? new Collection,
            $status ?? $this->fresh(),
            $watchSyncedAt,
            $now ?? Carbon::now(),
        );
    }

    public function test_a_recently_synced_watch_is_stated_without_being_a_gap(): void
    {
        $now = Carbon::parse('2026-08-01 14:00:00');
        $flag = $this->evaluate(watchSyncedAt: $now->copy()->subHour(), now: $now)['flags']['watch'];

        $this->assertFalse($flag['gap']);
        $this->assertNull($flag['detail']);
        $this->assertStringContainsString('13:00', $flag['label']);
    }

    public function test_a_session_newer_than_the_sync_is_a_gap_whatever_the_clock_says(): void
    {
        $now = Carbon::parse('2026-08-01 18:30:00');
        // Synced half an hour ago, so the clock is happy, but the mirror
        // holds a session that started after it, which can only mean the
        // watch has more the mirror has not seen.
        $flags = $this->evaluate(
            activities: collect([(object) ['date' => '2026-08-01', 'start_local' => '2026-08-01 18:10:00']]),
            watchSyncedAt: $now->copy()->subMinutes(30),
            now: $now,
        )['flags'];

        $this->assertTrue($flags['watch']['gap']);
        $this->assertStringContainsString('holding data the mirror has not seen', $flags['watch']['detail']);
    }

    public function test_without_a_sync_stamp_the_watch_says_nothing_rather_than_guessing(): void
    {
        $this->assertNull($this->evaluate()['flags']['watch']);
    }

    public function test_a_broken_login_makes_the_fetch_a_gap_and_carries_its_hint(): void
    {
        $status = DataStatus::evaluate(
            Carbon::now()->toIso8601String(),
            null,
            (object) ['error' => 'GarminConnectAuthenticationError', 'fetched_at' => Carbon::now()->toIso8601String()],
        );

        $flag = $this->evaluate(status: $status)['flags']['fetch'];

        $this->assertTrue($flag['gap']);
        $this->assertNotNull($flag['detail']);
    }

    public function test_a_stale_watch_alone_does_not_make_the_fetch_a_gap(): void
    {
        // The fetch did its job; the watch is the one holding things up,
        // and the watch flag beside it already says so.
        $status = DataStatus::evaluate(
            Carbon::now()->toIso8601String(),
            Carbon::now()->subHours(9),
            null,
        );

        $this->assertFalse($this->evaluate(status: $status)['flags']['fetch']['gap']);
    }

    public function test_active_calories_without_a_session_are_flagged_against_the_athletes_own_quiet_days(): void
    {
        $now = Carbon::parse('2026-08-01 09:00:00');
        // Twelve quiet days around 300 kcal, then a 900 kcal day with no
        // session on it: three times the usual and well past the floor.
        $days = collect(range(2, 13))->map(fn (int $i): object => $this->day($i, 300))
            ->push($this->day(1, 900))
            ->values();

        $flag = $this->evaluate(days: $days, now: $now)['flags']['activity'];

        $this->assertTrue($flag['gap']);
        $this->assertStringContainsString('900', $flag['detail']);
        $this->assertStringContainsString('300', $flag['detail']);
    }

    public function test_a_busy_day_within_the_athletes_own_range_is_not_flagged(): void
    {
        $now = Carbon::parse('2026-08-01 09:00:00');
        // 700 against a 600 kcal habit: above it, but neither half again
        // as much nor 250 kcal clear of it.
        $days = collect(range(2, 13))->map(fn (int $i): object => $this->day($i, 600))
            ->push($this->day(1, 700))
            ->values();

        $this->assertNull($this->evaluate(days: $days, now: $now)['flags']['activity']);
    }

    public function test_a_recorded_session_explains_the_burn_and_ends_the_question(): void
    {
        $now = Carbon::parse('2026-08-01 09:00:00');
        $days = collect(range(2, 13))->map(fn (int $i): object => $this->day($i, 300))
            ->push($this->day(1, 900))
            ->values();

        $this->assertNull($this->evaluate(
            days: $days,
            activities: collect([$this->workout(1)]),
            now: $now,
        )['flags']['activity']);
    }

    public function test_too_little_history_answers_nothing_rather_than_guessing_a_baseline(): void
    {
        $now = Carbon::parse('2026-08-01 09:00:00');
        $days = collect([$this->day(2, 300), $this->day(1, 900)]);

        $this->assertNull($this->evaluate(days: $days, now: $now)['flags']['activity']);
    }

    public function test_the_gap_count_is_what_the_strip_reports(): void
    {
        $now = Carbon::parse('2026-08-01 14:00:00');

        $quality = $this->evaluate(
            watchSyncedAt: $now->copy()->subHours(9),
            now: $now,
        );

        // The stale watch is the one gap of this afternoon.
        $this->assertSame(1, $quality['gaps']);
    }
}
