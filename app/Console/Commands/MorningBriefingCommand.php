<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PushSend;
use App\Models\User;
use App\Push\MorningBriefing;
use App\Push\WebPush;
use App\Tenancy\ActingUser;
use Illuminate\Console\Command;
use Throwable;

/**
 * Rings once per morning with the day's briefing: readiness, the verdict
 * word, today's training window, one focus. The wording itself lives in
 * App\Push\MorningBriefing and is composed again when the notification is
 * shown; this command only decides whether the phone should buzz at all.
 *
 * Honesty before cadence: a morning where the fetch brought nothing for
 * today sends nothing. A briefing that quotes yesterday's readiness as if
 * it were this morning's would spend the trust every later push runs on.
 *
 * Once per morning per athlete. Everyone who has allowed notifications
 * gets their own briefing from their own mirror, and one athlete whose
 * watch has nothing to say is silent without silencing the rest.
 */
class MorningBriefingCommand extends Command
{
    protected $signature = 'app:morning-briefing';

    protected $description = 'Send the once-per-day morning briefing push';

    public function handle(MorningBriefing $briefing, WebPush $push): int
    {
        // Everybody with a device to ring, each in their own context:
        // ActingUser::for points the mirror at that athlete, so the
        // readiness this composes is theirs and reaches their phones
        // only. Nobody hears anybody else's morning.
        foreach (User::reachableByPush() as $athlete) {
            ActingUser::for($athlete, fn () => $this->briefOne($athlete, $briefing, $push));
        }

        return self::SUCCESS;
    }

    /**
     * One athlete's morning, composed and rung.
     *
     * Failures stay with the athlete they belong to. A mirror that cannot
     * be read, a push service that refuses: neither is a reason for the
     * next athlete to go without their briefing, and the scheduler's log
     * is where the reason goes.
     */
    private function briefOne(User $athlete, MorningBriefing $briefing, WebPush $push): void
    {
        if (PushSend::sentToday(PushSend::KIND_BRIEFING, $athlete) !== null) {
            return;
        }

        try {
            $composed = $briefing->compose();
        } catch (Throwable $exception) {
            $this->warn('user '.$athlete->id.': '.$exception->getMessage());

            return;
        }

        if ($composed === null) {
            $this->info('user '.$athlete->id.': no data for today yet, staying silent');

            return;
        }

        $woken = $push->wakeAll($athlete->pushSubscriptions()->get(), 'briefing');

        if ($woken === 0) {
            // Either no key pair, or every push service refused. WebPush
            // has already logged the refusals; the ledger stays untouched
            // because it records deliveries, and nothing was delivered.
            $this->warn('user '.$athlete->id.': morning briefing not delivered to any device');

            return;
        }

        PushSend::record(PushSend::KIND_BRIEFING, $woken, $athlete);
        $this->info(sprintf('user %d: morning briefing sent to %d device(s)', $athlete->id, $woken));
    }
}
