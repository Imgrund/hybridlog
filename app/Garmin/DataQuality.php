<?php

declare(strict_types=1);

namespace App\Garmin;

use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Whether today's numbers are standing on complete data.
 *
 * Two real incidents in one week, both of them silent. The watch had last
 * uploaded at 16:32, so an evening workout was missing and the day looked
 * like a rest day. An afternoon of SUP and swimming went untracked, so the
 * day's burn was right while the training picture was materially wrong.
 *
 * Neither was a bug. Each was the data quietly being less than it looked,
 * which is the one failure a dashboard cannot show by drawing its numbers
 * more carefully. So the flags are stated, once, in a line that is always
 * there.
 *
 * Wording is part of the design. Every flag here names a gap, never a
 * failure and never a behaviour: probable untracked activity is a question
 * about the log rather than a claim about the athlete. Nothing here
 * notifies, and nothing here escalates a colour on its own.
 */
class DataQuality
{
    /**
     * How far a day's active calories may run past the usual untracked day
     * before the gap is worth naming, as a factor and as an absolute floor.
     * Both have to be cleared: a factor alone fires on quiet days where 90
     * against 60 kcal means nothing, and a floor alone fires on every
     * genuinely busy Saturday.
     */
    private const ACTIVE_SURPLUS_FACTOR = 1.5;

    private const ACTIVE_SURPLUS_KCAL = 250;

    /** How much history the untracked-day baseline is read over. */
    private const BASELINE_DAYS = 30;

    /**
     * Everything the line shows, as flat facts. The view decides what to
     * draw; this decides what is true.
     *
     * @param  Collection<int, object>  $days  mirror days, newest last
     * @param  Collection<int, object>  $activities
     * @return array<string, mixed>
     */
    public function evaluate(
        Collection $days,
        Collection $activities,
        DataStatus $status,
        ?Carbon $watchSyncedAt,
        ?Carbon $now = null,
    ): array {
        $now ??= Carbon::now();

        $flags = [
            'watch' => $this->watch($watchSyncedAt, $activities, $now),
            'fetch' => $this->fetch($status),
            'activity' => $this->untrackedActivity($days, $activities, $now),
        ];

        return [
            'flags' => $flags,
            // What the whole strip amounts to, for the caller that only
            // wants to know whether to say anything at all.
            'gaps' => count(array_filter($flags, fn (?array $f): bool => $f !== null && $f['gap'])),
        ];
    }

    /**
     * The watch's last upload. Stale by the clock, and stale by evidence:
     * an activity that started after the last sync is proof the watch is
     * holding data the mirror has never seen, whatever the hour says.
     *
     * @param  Collection<int, object>  $activities
     * @return array{gap: bool, label: string, detail: string|null}|null
     */
    private function watch(?Carbon $syncedAt, Collection $activities, Carbon $now): ?array
    {
        $described = WatchSync::describe($syncedAt, $now);

        if ($described === null || $syncedAt === null) {
            return null;
        }

        $newerSession = $activities
            ->filter(fn (object $a): bool => $a->start_local !== null && Carbon::parse($a->start_local)->gt($syncedAt))
            ->count();

        return [
            'gap' => $described['stale'] || $newerSession > 0,
            'label' => __('Watch synced :label', ['label' => $described['label']]),
            'detail' => match (true) {
                $newerSession > 0 => __('A session started after that sync, so the watch is holding data the mirror has not seen. Open the Garmin Connect app to sync.'),
                $described['stale'] => __('New values only arrive after a sync from the Garmin Connect app; until then a day can look emptier than it was.'),
                default => null,
            },
        ];
    }

    /**
     * The mirror's own last successful read. It reuses the verdict the
     * header already carries rather than re-deriving one: two places
     * deciding what "stale" means is how they end up disagreeing.
     *
     * @return array{gap: bool, label: string, detail: string|null}
     */
    private function fetch(DataStatus $status): array
    {
        $at = $status->lastFetch !== null ? Carbon::parse($status->lastFetch) : null;

        return [
            'gap' => $status->state !== 'fresh' && $status->state !== 'watch_stale',
            'label' => $at === null
                ? __('No fetch yet')
                : __('Fetched :label', ['label' => $at->isToday() ? $at->format('H:i') : $at->isoFormat(__('MMM D, HH:mm'))]),
            'detail' => $status->hint,
        ];
    }

    /**
     * Active calories with no session to explain them: probably a workout
     * that was never recorded, which makes the day's burn right and the
     * training picture wrong.
     *
     * Read against this athlete's own untracked days rather than a fixed
     * number, because "high" is a property of the person: 600 active
     * calories is a busy Saturday for one athlete and a rest day for
     * another. Only finished days are judged: today's active calories are
     * still growing, and its session may still be uploaded.
     *
     * @param  Collection<int, object>  $days
     * @param  Collection<int, object>  $activities
     * @return array{gap: bool, label: string, detail: string|null}|null
     */
    private function untrackedActivity(Collection $days, Collection $activities, Carbon $now): ?array
    {
        $datesWithSession = $activities->pluck('date')->unique()->flip();
        $window = $days
            ->filter(fn (object $d): bool => $d->date < $now->toDateString()
                && $d->date >= $now->copy()->subDays(self::BASELINE_DAYS)->toDateString()
                && ($d->calories_active ?? null) !== null);

        $yesterday = $window->sortBy('date')->last();

        if ($yesterday === null || $datesWithSession->has($yesterday->date)) {
            return null;
        }

        // The baseline: what a day without a recorded session usually costs
        // this athlete. Median, so one untracked hike does not raise the bar
        // that would have caught the next one.
        $quiet = $window
            ->filter(fn (object $d): bool => ! $datesWithSession->has($d->date) && $d->date !== $yesterday->date)
            ->map(fn (object $d): float => (float) $d->calories_active)
            ->sort()
            ->values();

        if ($quiet->count() < 5) {
            return null;
        }

        $baseline = (float) $quiet->median();
        $actual = (float) $yesterday->calories_active;

        if ($actual < $baseline * self::ACTIVE_SURPLUS_FACTOR || $actual - $baseline < self::ACTIVE_SURPLUS_KCAL) {
            return null;
        }

        return [
            'gap' => true,
            'label' => __('Possible untracked session'),
            'detail' => __(':date burned :actual active calories with no recorded session; a day without one usually costs about :baseline. The burn is right, the training picture is missing a session.', [
                'date' => Carbon::parse($yesterday->date)->isoFormat(__('MMM D')),
                'actual' => NumberFormat::format($actual, 0),
                'baseline' => NumberFormat::format($baseline, 0),
            ]),
        ];
    }
}
