<?php

declare(strict_types=1);

namespace App\Push;

use App\Garmin\GarminData;
use App\Garmin\Insights;

/**
 * The conditional evening push. It exists for one finding, and without
 * it composes nothing at all: a bedtime that has been drifting past the
 * rule of thumb. Silence is the designed default, not a failure mode;
 * an evening push that always finds something is the one that gets
 * switched off.
 *
 * Like the morning briefing this is asked twice: once by the command
 * before it rings, and again by the push feed when the notification is
 * about to be shown, so the numbers on the phone are those of the
 * moment it is glanced at.
 */
class EveningNudge
{
    /**
     * The rule of thumb for a stable sleep window. Deliberately the
     * common 30 rather than the sleep panel's warning edge of 35: the
     * panel warns, this one merely invites, and an invitation may come
     * a little earlier than a warning.
     */
    private const BEDTIME_DRIFT_MINUTES = 30;

    public function __construct(
        private GarminData $garmin,
        private Insights $insights,
    ) {}

    /**
     * The nudge, or null when the evening has nothing worth saying.
     *
     * @return array{title: string, body: string, url: string}|null
     */
    public function compose(): ?array
    {
        return $this->bedtimeDrift();
    }

    /**
     * The bedtime window is drifting, and the last fortnight yields a
     * concrete time to name. Both parts are required: a drift warning
     * without a time to keep is homework, and the median is only worth
     * naming while the spread says the window needs holding.
     *
     * @return array{title: string, body: string, url: string}|null
     */
    private function bedtimeDrift(): ?array
    {
        $consistency = $this->insights->sleepConsistency($this->garmin->sleep(30));

        $spread = $consistency['bedtimeSdMin'];
        $median = $consistency['bedtimeMedian'];

        if ($spread === null || $spread <= self::BEDTIME_DRIFT_MINUTES || $median === null) {
            return null;
        }

        return [
            'title' => __('Bedtime window'),
            'body' => __('Bedtime has drifted by ±:minutes min. In bed before :time holds your window (median of the last 14 nights).', [
                'minutes' => $spread,
                'time' => $median,
            ]),
            'url' => route('dashboard'),
        ];
    }
}
