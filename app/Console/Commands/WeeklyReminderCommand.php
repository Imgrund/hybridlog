<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PushSend;
use App\Models\User;
use App\Push\WebPush;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

/**
 * The Sunday-evening reminder for the weekly AI report. The notification's
 * tap opens claude.ai with the prepared prompt (see App\Push\WeeklyReminder);
 * no model runs on this server, which is the deal the whole roadmap made.
 *
 * Once per report week, checked against the ledger rather than assumed
 * from the schedule. The report week is the one ending on the run's own
 * or most recent Sunday (the same resolution the prompt makes), so a
 * manual catch-up run on a Monday must not ring again for the Sunday
 * that already did.
 */
class WeeklyReminderCommand extends Command
{
    protected $signature = 'app:weekly-report-reminder';

    protected $description = 'Send the once-per-week reminder push for the weekly report';

    public function handle(WebPush $push): int
    {
        $weekEnd = now()->startOfDay();
        if (! $weekEnd->isSunday()) {
            $weekEnd = $weekEnd->previous(CarbonInterface::SUNDAY);
        }

        // The one sender that composes nothing: it hands the athlete into
        // their chat app, where the prompt reads their mirror as them. So
        // there is no tenant context to set here, only whose ledger to
        // check and whose devices to ring.
        foreach (User::reachableByPush() as $athlete) {
            $this->remindOne($athlete, $push, $weekEnd);
        }

        return self::SUCCESS;
    }

    private function remindOne(User $athlete, WebPush $push, CarbonInterface $weekEnd): void
    {
        $sentForThisWeek = PushSend::query()->for($athlete)
            ->where('kind', PushSend::KIND_WEEKLY)
            ->where('date', '>=', $weekEnd->copy()->subDays(6)->toDateString())
            ->exists();

        if ($sentForThisWeek) {
            return;
        }

        $woken = $push->wakeAll($athlete->pushSubscriptions()->get(), 'weeklyreport');

        if ($woken === 0) {
            $this->warn('user '.$athlete->id.': weekly report reminder not delivered to any device');

            return;
        }

        PushSend::record(PushSend::KIND_WEEKLY, $woken, $athlete);
        $this->info(sprintf('user %d: weekly report reminder sent to %d device(s)', $athlete->id, $woken));
    }
}
