<?php

declare(strict_types=1);

namespace App\Push;

use App\Models\AthleteProfile;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The Sunday reminder for the weekly AI report. No server-side model call
 * anywhere in it: the notification's tap opens claude.ai with a prepared
 * prompt in the query string, and the athlete fires it from the phone.
 *
 * The prompt mirrors what the MCP weekly-report prompt does (compare the
 * week, verdict over description, the chat answer is the report).
 * WeeklyReportPrompt counts a Sunday as the end of its own week, which is
 * why a Sunday-evening reminder and weeks_back 0 mean the same seven days;
 * the dates are still spelled out here so the chat cannot resolve "this
 * week" differently on a Monday-morning tap.
 */
class WeeklyReminder
{
    /**
     * @return array{title: string, body: string, url: string}
     */
    public function compose(?User $user = null, ?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now())->startOfDay();

        // The prompt is written for the chat, so it follows the reader's
        // stored language rather than the language of whichever device
        // shows the notification. Read without creating a profile row.
        $locale = $user?->athleteProfile?->locale;
        $locale = in_array($locale, AthleteProfile::LOCALES, true) ? $locale : 'en';

        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            return [
                'title' => __('Weekly report'),
                'body' => __('The training week is done. One tap opens the chat with the report prompt ready to send.'),
                'url' => 'https://claude.ai/new?q='.rawurlencode($this->prompt($today)),
            ];
        } finally {
            app()->setLocale($previous);
        }
    }

    /**
     * The prepared prompt, with the week boundaries resolved to dates the
     * same way WeeklyReportPrompt resolves them for weeks_back 0.
     */
    private function prompt(CarbonImmutable $today): string
    {
        $end = $today->isSunday() ? $today : $today->previous(CarbonImmutable::SUNDAY);
        $start = $end->subDays(6);
        $priorEnd = $start->subDay();
        $priorStart = $priorEnd->subDays(6);

        $parts = [
            __('Create my weekly report over the Garmin Health connector: compare the week :from to :to with the week before, :priorFrom to :priorTo, on training load, sleep and HRV.', [
                'from' => $start->toDateString(),
                'to' => $end->toDateString(),
                'priorFrom' => $priorStart->toDateString(),
                'priorTo' => $priorEnd->toDateString(),
            ]),
        ];

        if ($end->isSameDay($today)) {
            $parts[] = __('Today is the last day of this week, so treat a thin Sunday as a gap, not as a finding.');
        }

        $parts[] = __('Write a verdict, not a description: what shifted, what that means for the coming week, what the one change would be.');
        $parts[] = __('The chat answer is the report; nothing needs saving.');

        return implode(' ', $parts);
    }
}
