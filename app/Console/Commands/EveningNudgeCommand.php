<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PushSend;
use App\Models\User;
use App\Push\EveningNudge;
use App\Push\WebPush;
use App\Tenancy\ActingUser;
use Illuminate\Console\Command;
use Throwable;

/**
 * Rings at most once per evening, and only when App\Push\EveningNudge has
 * its one finding to report: a drifting bedtime with a concrete window to
 * keep. Every other evening exits quietly, which is the designed and by
 * far the common case.
 */
class EveningNudgeCommand extends Command
{
    protected $signature = 'app:evening-nudge';

    protected $description = 'Send the conditional once-per-day evening nudge push';

    public function handle(EveningNudge $nudge, WebPush $push): int
    {
        // Each athlete's evening is their own: composed from their mirror
        // while ActingUser::for points at them, delivered to their
        // devices (see MorningBriefingCommand).
        foreach (User::reachableByPush() as $athlete) {
            ActingUser::for($athlete, fn () => $this->nudgeOne($athlete, $nudge, $push));
        }

        return self::SUCCESS;
    }

    private function nudgeOne(User $athlete, EveningNudge $nudge, WebPush $push): void
    {
        if (PushSend::sentToday(PushSend::KIND_NUDGE, $athlete) !== null) {
            return;
        }

        try {
            // Usually nothing: the nudge has one condition and most
            // evenings do not meet it.
            if ($nudge->compose() === null) {
                return;
            }
        } catch (Throwable $exception) {
            $this->warn('user '.$athlete->id.': '.$exception->getMessage());

            return;
        }

        $woken = $push->wakeAll($athlete->pushSubscriptions()->get(), 'nudge');

        if ($woken === 0) {
            $this->warn('user '.$athlete->id.': evening nudge not delivered to any device');

            return;
        }

        PushSend::record(PushSend::KIND_NUDGE, $woken, $athlete);
        $this->info(sprintf('user %d: evening nudge sent to %d device(s)', $athlete->id, $woken));
    }
}
