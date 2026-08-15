<?php

namespace App\Garmin;

use Carbon\Carbon;

/**
 * Presentation logic for the watch's last known sync to Garmin Connect.
 *
 * A fetch can only mirror what the watch has already uploaded, so when
 * "Fetch from Garmin" visibly changes nothing, the reason is almost
 * always a watch that has not synced to the Connect app. The header
 * states that instead of leaving the click looking broken.
 */
class WatchSync
{
    /**
     * The watch normally uploads several times a day; beyond this gap
     * the dashboard explains itself instead of silently going stale.
     */
    private const STALE_AFTER_HOURS = 3;

    /** @return array{label: string, stale: bool}|null */
    public static function describe(?Carbon $syncedAt, ?Carbon $now = null): ?array
    {
        if ($syncedAt === null) {
            return null;
        }

        $now ??= Carbon::now();

        return [
            'label' => $syncedAt->isSameDay($now)
                ? $syncedAt->format('H:i')
                : $syncedAt->isoFormat(__('MMM D, HH:mm')),
            'stale' => $syncedAt->diffInHours($now) >= self::STALE_AFTER_HOURS,
        ];
    }
}
