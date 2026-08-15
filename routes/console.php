<?php

use App\Demo\DemoMode;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * The recurring work.
 *
 * One process runs `php artisan schedule:work` (or a per-minute cron
 * calling `schedule:run`) and owns both of these; docker/work.sh starts
 * it next to the queue worker.
 *
 * The weekly report itself is not here: the Sunday push below hands the
 * athlete into their chat app, and the MCP weekly-report prompt is the
 * one copy of the instructions.
 */
foreach (config('garmin.fetch.times') as $time) {
    // Every connected athlete, one after the other: see
    // App\Console\Commands\FetchEveryAthleteCommand for why in turn and
    // not at once. A slot is therefore as long as the installation has
    // athletes, which withoutOverlapping below is what accounts for.
    Schedule::command('garmin:fetch-all')
        ->dailyAt($time)
        // The scheduler is not the only thing that starts a fetch, and
        // two of them writing the same days is wasted work at best. With
        // several athletes in one slot it also keeps the 13:00 run from
        // starting while the 09:30 one is still working through them.
        ->withoutOverlapping()
        // A fetch takes about a minute and may take much longer on a
        // backfill; in the foreground it would hold up everything else
        // the scheduler has to do in that time.
        ->runInBackground()
        ->appendOutputTo(storage_path('logs/fetch.log'));
}

// Ten minutes after the morning fetch with the default times: the fetch
// takes about a minute, so 09:40 already reads today's numbers, and the
// alert check five minutes later stays the rarer, sharper voice that may
// follow the briefing rather than be drowned out by it.
Schedule::command('app:morning-briefing')
    ->dailyAt('09:40')
    ->appendOutputTo(storage_path('logs/push.log'));

// A quarter of an hour after the morning fetch with the default times, so
// the thresholds are judged on today's readiness and not yesterday's.
Schedule::command('app:health-alerts')
    ->dailyAt('09:45')
    ->appendOutputTo(storage_path('logs/alerts.log'));

// After the evening fetch with the default times, so the day it judges is
// essentially complete: the workout is uploaded, and a bedtime worth
// naming is still ahead rather than behind.
// Usually silent; see App\Push\EveningNudge for the one condition.
Schedule::command('app:evening-nudge')
    ->dailyAt('21:15')
    ->appendOutputTo(storage_path('logs/push.log'));

// Sunday evening, because the MCP weekly-report prompt counts a Sunday as
// the end of its own week: the reminder, the prepared prompt and the
// report all mean the same seven days that are just finishing.
Schedule::command('app:weekly-report-reminder')
    ->sundays()
    ->at('19:00')
    ->appendOutputTo(storage_path('logs/push.log'));

// The lifecycles the models declare (McpToolCall keeps 90 days), applied
// once a night. Quietly at an hour nothing else here occupies.
Schedule::command('model:prune')
    ->dailyAt('03:30')
    ->appendOutputTo(storage_path('logs/prune.log'));

// The public demo, put back the way it ships while nobody is walking
// through it: the shared password, no traces of yesterday's visitors, a
// mirror seeded from scratch.
//
// Registered only where this installation is a demo. Everywhere else the
// same line would be a scheduled deletion of somebody's own symptom log
// and a nightly reset of their password, and a command that harmful
// should not be sitting in a schedule at all. The command refuses by
// itself as well, which is the belt to this brace.
if (DemoMode::enabled()) {
    Schedule::command('demo:reset')
        ->dailyAt('04:00')
        // Truncating and refilling a mirror takes a moment, and a second
        // reset starting on top of the first would be seeding into a
        // table the first one is still writing.
        ->withoutOverlapping()
        ->runInBackground()
        ->appendOutputTo(storage_path('logs/demo.log'));
}
