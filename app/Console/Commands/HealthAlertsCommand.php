<?php

namespace App\Console\Commands;

use App\Garmin\GarminData;
use App\Garmin\NumberFormat;
use App\Garmin\TrainingLoad;
use App\Models\HealthAlert;
use App\Models\User;
use App\Push\WebPush;
use App\Tenancy\ActingUser;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Morning alert check with exactly three hard rules: readiness floor,
 * HRV under the personal band for consecutive days, and a critical
 * acute:chronic workload ratio. Fires at most one notification per rule
 * and day (ledger: health_alerts), always over web push to the subscribed
 * devices and additionally through the optional GARMIN_ALERT_COMMAND,
 * which receives the message as its single argument.
 */
class HealthAlertsCommand extends Command
{
    protected $signature = 'app:health-alerts';

    protected $description = 'Check the morning health thresholds, notify once per rule';

    private const READINESS_FLOOR = 25;

    private const HRV_CONSECUTIVE_DAYS = 3;

    /** Mirrors ChartBundle::ACTIVITY_HISTORY_DAYS so the ACWR fallback sees the same load history. */
    private const ACTIVITY_HISTORY_DAYS = 400;

    public function handle(GarminData $garmin, TrainingLoad $trainingLoad, WebPush $push): int
    {
        // Every account, not only those with a device to ring: an alert is
        // recorded whether or not it could be delivered, and the record is
        // what the dashboard's banner reads. deliver() notes why a buzz
        // did not happen.
        foreach (User::query()->orderBy('id')->get() as $athlete) {
            ActingUser::for($athlete, fn () => $this->checkOne($athlete, $garmin, $trainingLoad, $push));
        }

        return self::SUCCESS;
    }

    /**
     * One athlete's thresholds, judged against their own mirror.
     *
     * Wrapped, because this is the run that reaches every account: one
     * athlete who has never connected Garmin, or whose mirror is midway
     * through its first backfill, must not cost everybody else their
     * alerts.
     */
    private function checkOne(User $athlete, GarminData $garmin, TrainingLoad $trainingLoad, WebPush $push): void
    {
        $today = now()->toDateString();

        try {
            $fired = $this->firedRules($garmin, $trainingLoad);
        } catch (Throwable $exception) {
            $this->warn('user '.$athlete->id.': '.$exception->getMessage());

            return;
        }

        $due = array_filter(
            $fired,
            fn (string $rule): bool => ! HealthAlert::for($athlete)->where('rule', $rule)->where('date', $today)->exists(),
            ARRAY_FILTER_USE_KEY,
        );

        if ($due === []) {
            return;
        }

        // One buzz for the whole run rather than one per rule: the push
        // feed joins today's alerts into a single notification anyway,
        // and two buzzes seconds apart read as a malfunction.
        $woken = $push->wakeAll($athlete->pushSubscriptions()->get(), 'healthalert');

        foreach ($due as $rule => $message) {
            $this->deliver($athlete, $rule, $message, $woken);
        }
    }

    /** @return array<string, string> rule key => notification message */
    private function firedRules(GarminData $garmin, TrainingLoad $trainingLoad): array
    {
        $fired = [];

        // (a) Readiness under 25. Same reading the dashboard hero makes:
        // the intraday snapshot wins over the frozen morning value.
        $readiness = $garmin->readiness(30)->sortBy('date')->last();
        $score = $readiness->current_score ?? $readiness->score ?? null;
        if ($score !== null && (int) $score < self::READINESS_FLOOR) {
            $fired['readiness'] = __('Readiness :score: zone 1 only today.', ['score' => (int) $score]);
        }

        // (b) HRV weekly average under the personal balanced band on 3+
        // consecutive calendar days. A missing night breaks the streak:
        // no measurement is not evidence of suppression.
        $hrvStreak = $this->hrvStreak($garmin->hrv(30));
        if ($hrvStreak !== null && $hrvStreak['days'] >= self::HRV_CONSECUTIVE_DAYS) {
            $fired['hrv'] = __('HRV below the normal band for :days days (:weekly under :low ms): easy work only today.', [
                'days' => $hrvStreak['days'],
                'weekly' => NumberFormat::format($hrvStreak['weekly'], 0),
                'low' => NumberFormat::format($hrvStreak['bandLow'], 0),
            ]);
        }

        // (c) ACWR in the critical zone, computed exactly like the
        // dashboard: Garmin's ratio first, own load fallback second.
        $acwr = $trainingLoad->acwr(
            $garmin->trainingStatus(120),
            $trainingLoad->series($garmin->activities(self::ACTIVITY_HISTORY_DAYS))['dailyLoad'],
        );
        if (TrainingLoad::acwrStatus($acwr['value']) === 'critical') {
            $fired['acwr'] = __('Load ratio :value above the critical threshold :threshold: take volume out this week.', [
                'value' => NumberFormat::format($acwr['value'], 2),
                'threshold' => NumberFormat::format(TrainingLoad::ACWR_CRITICAL, 1),
            ]);
        }

        return $fired;
    }

    /**
     * Length of the trailing run of days whose weekly HRV average sits
     * under the balanced band's lower edge, counted backwards from the
     * newest row over consecutive calendar days.
     *
     * @return array{days: int, weekly: float, bandLow: float}|null
     */
    private function hrvStreak($hrv): ?array
    {
        $rows = $hrv->sortByDesc('date')->values();
        $latest = $rows->first();
        if ($latest === null || $latest->weekly_avg === null || $latest->baseline_balanced_low === null) {
            return null;
        }

        $days = 0;
        $expected = Carbon::parse($latest->date);
        foreach ($rows as $row) {
            $under = $row->weekly_avg !== null
                && $row->baseline_balanced_low !== null
                && (float) $row->weekly_avg < (float) $row->baseline_balanced_low;
            if (! $under || ! $expected->isSameDay(Carbon::parse($row->date))) {
                break;
            }
            $days++;
            $expected = $expected->subDay();
        }

        return [
            'days' => $days,
            'weekly' => (float) $latest->weekly_avg,
            'bandLow' => (float) $latest->baseline_balanced_low,
        ];
    }

    /**
     * The ledger row is written only after some channel took the alert:
     * a woken device counts, and so does a successful run of the optional
     * shell notifier. Web push did not replace that notifier, it replaced
     * the void an unset GARMIN_ALERT_COMMAND used to fire into.
     */
    private function deliver(User $owner, string $rule, string $message, int $woken): void
    {
        $delivered = $woken > 0;

        $command = config('garmin.alert_command');
        if (filled($command)) {
            $result = Process::run([$command, $message]);
            if ($result->successful()) {
                $delivered = true;
            } else {
                $this->warn(sprintf('notify failed for rule %s: %s', $rule, trim($result->errorOutput())));
            }
        }

        if (! $delivered) {
            // The rules still ran and the finding is still named on stdout,
            // where the scheduler's log picks it up. The ledger stays
            // untouched: it records deliveries, and nothing was delivered.
            $why = blank($command) && $owner->pushSubscriptions()->doesntExist()
                ? ', no device subscribed and no GARMIN_ALERT_COMMAND set'
                : '';
            $this->warn(sprintf('alert [%s] not delivered%s: %s', $rule, $why, $message));

            return;
        }

        HealthAlert::create(['user_id' => $owner->id, 'rule' => $rule, 'date' => now()->toDateString(), 'message' => $message]);
        $this->info(sprintf('alert sent [%s]: %s', $rule, $message));
    }
}
