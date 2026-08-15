<?php

namespace App\Garmin;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Collection;

/**
 * Read-only gateway to the Garmin mirror written by fetcher/fetch.py.
 */
class GarminData
{
    /**
     * The acting athlete's mirror, asked for per query rather than held.
     *
     * It used to be a constructor-assigned connection, which was right while
     * there was one mirror. There is one per athlete now, so the question
     * "whose data is this" has an answer that can change between two uses of
     * the same object: a scheduled sender walks tenants with one instance,
     * and a container-shared instance would otherwise keep whoever it was
     * built for. Mirror::connection() remembers the pinning per connection,
     * so asking every time costs nothing after the first.
     */
    protected function db(): ConnectionInterface
    {
        return Mirror::connection();
    }

    /**
     * Whether the dashboard is about to draw invented data.
     *
     * This used to be a filename: demo mode meant a second SQLite file.
     * The seeder now writes into the mirror itself and stamps its rows
     * fetched_at='demo' (fetcher/seed_demo.py), so the question belongs
     * to the data rather than to the connection. It asks the newest day
     * and not merely whether any demo row exists, because a real fetch
     * after a demo leaves the older seeded days behind and the banner
     * would otherwise never go away.
     */
    public function isDemo(): bool
    {
        return $this->db()->table('days')->orderByDesc('date')->value('fetched_at') === 'demo';
    }

    public function days(int $days = 120): Collection
    {
        return $this->db()->table('days')
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('date')
            ->get();
    }

    /**
     * How many days of history the mirror reaches back over, counting today.
     *
     * Measured on `days` because it is the broadest table: Garmin keeps daily
     * summaries longer than it keeps a night of sleep, so this is the widest
     * window any surface could honestly draw. The range switch asks for it to
     * decide which of its stages it can offer, which is why an empty mirror
     * answers 0 rather than 1: a dashboard with no days has no window at all.
     */
    public function mirrorSpanDays(): int
    {
        $oldest = $this->db()->table('days')->min('date');

        if (! $oldest) {
            return 0;
        }

        return Carbon::parse($oldest)->startOfDay()->diffInDays(now()->startOfDay()) + 1;
    }

    public function sleep(int $days = 120): Collection
    {
        return $this->db()->table('sleep')
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('date')
            ->get();
    }

    public function hrv(int $days = 120): Collection
    {
        return $this->db()->table('hrv')
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('date')
            ->get();
    }

    public function readiness(int $days = 30): Collection
    {
        return $this->db()->table('readiness')
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('date')
            ->get();
    }

    public function activities(int $days = 180): Collection
    {
        return $this->db()->table('activities')
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('start_local')
            ->get();
    }

    public function strengthSets(int $days = 90): Collection
    {
        return $this->db()->table('strength_sets')
            ->join('activities', 'activities.id', '=', 'strength_sets.activity_id')
            ->where('activities.date', '>=', now()->subDays($days)->toDateString())
            ->where(function ($q) {
                $q->whereNull('strength_sets.set_type')
                    ->orWhere('strength_sets.set_type', '!=', 'REST');
            })
            ->orderBy('activities.date')
            ->get([
                'strength_sets.*',
                'activities.date as activity_date',
                'activities.start_local as activity_start',
                'activities.type_key as activity_type',
            ]);
    }

    public function trainingStatus(int $days = 120): Collection
    {
        return $this->db()->table('training_status')
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('date')
            ->get();
    }

    /**
     * Whether the fetcher has mirrored any lap at all. Decides which
     * empty state the race card tells: "the fetcher has not been here
     * yet" is a different sentence from "no session had enough laps".
     */
    public function hasAnyLaps(): bool
    {
        return $this->db()->table('activity_laps')->exists();
    }

    /**
     * Activities carrying at least $minLaps laps, newest first: the
     * candidates of the race card's picker. The floor keeps single-lap
     * recordings (every circuit session has one) from posing as race-style sessions.
     */
    public function lappedActivities(int $minLaps, int $limit = 8): Collection
    {
        return $this->db()->table('activities')
            ->join('activity_laps', 'activity_laps.activity_id', '=', 'activities.id')
            ->groupBy('activities.id', 'activities.date', 'activities.start_local', 'activities.type_key', 'activities.name')
            ->havingRaw('count(*) >= ?', [$minLaps])
            ->orderByDesc('activities.start_local')
            ->limit($limit)
            ->get([
                'activities.id',
                'activities.date',
                'activities.start_local',
                'activities.type_key',
                'activities.name',
                $this->db()->raw('count(*) as lap_count'),
            ]);
    }

    public function activityLaps(int $activityId): Collection
    {
        return $this->db()->table('activity_laps')
            ->where('activity_id', $activityId)
            ->orderBy('lap_index')
            ->get();
    }

    public function fitnessAge(): ?object
    {
        return $this->db()->table('fitness_age')->orderByDesc('date')->first();
    }

    /** Newest snapshot of HR zones, max HR and lactate threshold. */
    public function heartProfile(): ?object
    {
        return $this->db()->table('heart_profile')->orderByDesc('date')->first();
    }

    public function bodyComp(int $days = 365): Collection
    {
        return $this->db()->table('body_comp')
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('ts')
            ->get();
    }

    /**
     * The hourly weather for the configured location.
     *
     * One day of slack at the front, because a night belongs to the date
     * it ends on but starts on the evening before, and cutting that
     * window needs the hours from that evening.
     *
     * Guarded on the table: a mirror built before this table existed is
     * still a working mirror, it just has no weather until the fetcher
     * next runs and creates it.
     */
    public function weather(int $days = 120): Collection
    {
        if (! $this->db()->getSchemaBuilder()->hasTable('weather_hourly')) {
            return new Collection;
        }

        return $this->db()->table('weather_hourly')
            ->where('date', '>=', now()->subDays($days + 1)->toDateString())
            ->orderBy('ts_local')
            ->get();
    }

    public function latestFetch(): ?string
    {
        // ok = 1 only: fetch.py also logs failed logins into fetch_log,
        // and a failure must never stamp the mirror as freshly fetched.
        return $this->db()->table('fetch_log')->where('ok', 1)->max('fetched_at');
    }

    /**
     * How far the running fetch has come: days begun out of days asked
     * for. Null while nothing runs, or while the running mark predates
     * the window being recorded (see FetchTrigger::currentRun).
     *
     * Progress is counted in fetch_log rows of kind "stats", because
     * that endpoint is the first call fetch_day makes for every day: one
     * such row per day means the run has reached that day, whatever the
     * endpoints after it return. Counted from the run's own start, since
     * every later run upserts the same (date, kind) rows. The comparison
     * is text against text, which both sides keep honest by writing
     * wall-clock stamps of one shape in the app's timezone; fetch.py's
     * now() says why the column looks like that.
     *
     * @param  array{started: string, from: ?string, to: ?string, backfill: bool}|null  $run
     * @return array{done: int, total: int, backfill: bool}|null
     */
    public function fetchProgress(?array $run): ?array
    {
        if ($run === null || $run['from'] === null || $run['to'] === null) {
            return null;
        }

        $total = (int) Carbon::parse($run['from'])->diffInDays(Carbon::parse($run['to'])) + 1;

        $done = $this->db()->table('fetch_log')
            ->where('kind', 'stats')
            ->where('fetched_at', '>=', Carbon::parse($run['started'])->format('Y-m-d\TH:i:s'))
            ->distinct()
            ->count('date');

        return [
            // Capped, so a clock nudge or a stray row can never have the
            // page announce day 95 of 90.
            'done' => (int) min($done, $total),
            'total' => $total,
            'backfill' => $run['backfill'],
        ];
    }

    /**
     * The login failure that currently blocks the mirror, or null. A
     * failure row older than the last successful fetch is history, not
     * a problem: the login has evidently worked since.
     */
    public function authFailure(): ?object
    {
        $failure = $this->db()->table('fetch_log')
            ->where('kind', 'login')
            ->where('ok', 0)
            ->orderByDesc('fetched_at')
            ->first();

        if ($failure === null) {
            return null;
        }

        $lastSuccess = $this->latestFetch();

        return ($lastSuccess === null || $failure->fetched_at > $lastSuccess) ? $failure : null;
    }

    /**
     * The verdict every surface shows, assembled from the mirror's own
     * bookkeeping plus the one thing that bookkeeping cannot tell you:
     * whether it came from Garmin at all. The seeder writes fetch_log
     * exactly as a fetch does, so without isDemo() the log of a fresh
     * Quickstart installation reads as a successful run and the header
     * calls it connected.
     */
    public function dataStatus(): DataStatus
    {
        return DataStatus::evaluate(
            $this->latestFetch(),
            $this->watchLastSync(),
            $this->authFailure(),
            seeded: $this->isDemo(),
        );
    }

    /**
     * Last known watch→Garmin upload, in app time. Null until the
     * fetcher has written one; mirrors created before the device_sync
     * table existed lack it entirely, hence the schema guard.
     */
    public function watchLastSync(): ?Carbon
    {
        if (! $this->db()->getSchemaBuilder()->hasTable('device_sync')) {
            return null;
        }

        $utc = $this->db()->table('device_sync')->max('last_sync_utc');

        return $utc
            ? Carbon::parse($utc, 'UTC')->setTimezone(config('app.timezone'))
            : null;
    }
}
