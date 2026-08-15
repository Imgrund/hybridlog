<?php

declare(strict_types=1);

namespace App\Mcp\Prompts;

use Carbon\CarbonImmutable;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('weekly-report')]
#[Title('Weekly report')]
#[Description(
    'Compare the week that just ended (Mon-Sun) with the one before it and answer with the '.
    'verdict in the chat. Resolves the week boundaries to concrete dates.'
)]
class WeeklyReportPrompt extends Prompt
{
    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument(
                name: 'weeks_back',
                description: 'How many weeks to step back. 0 (default) is the week ending on the most recent Sunday, 1 the one before it. Use it to catch up on a report that was missed.',
            ),
        ];
    }

    public function handle(Request $request): Response
    {
        $back = max(0, (int) $request->get('weeks_back', 0));
        $today = CarbonImmutable::today();

        // The report is meant to run Sunday evening, so a Sunday counts as
        // the end of its own week rather than sending the job a week back.
        $end = ($today->isSunday() ? $today : $today->previous(CarbonImmutable::SUNDAY))->subWeeks($back);
        $start = $end->subDays(6);
        $priorEnd = $start->subDay();
        $priorStart = $priorEnd->subDays(6);

        $sameDayCaveat = $end->isSameDay($today)
            ? "\nToday is the last day of this week, so Sunday is still incomplete (dinner, the ".
              "night's sleep). Treat a Sunday dip as a gap, not as a finding.\n"
            : '';

        $format = __('MMMM D, YYYY');
        [$from, $to, $priorFrom, $priorTo] = array_map(
            fn (CarbonImmutable $day) => $day->isoFormat($format),
            [$start, $end, $priorStart, $priorEnd],
        );

        return Response::text(<<<PROMPT
            Write the weekly report for {$from} to {$to}
            (SQL range: date between '{$start->toDateString()}' and '{$end->toDateString()}')
            against the previous week {$priorFrom} to {$priorTo}
            (date between '{$priorStart->toDateString()}' and '{$priorEnd->toDateString()}').

            Compare the two weeks on three points: training load, sleep and HRV.
            {$sameDayCaveat}
            Write a verdict, not a description: what shifted, what that means for the coming week,
            what the one change would be. Numbers only where they carry the point. The chat answer
            is the report; nothing is saved anywhere.
            PROMPT);
    }
}
