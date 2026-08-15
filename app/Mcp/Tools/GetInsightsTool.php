<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Garmin\GarminData;
use App\Garmin\Insights;
use App\Garmin\TrainingLoad;
use App\Mcp\Concerns\ChecksConnectorPermissions;
use App\Mcp\LoggedTool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(
    'The app\'s own reading of the body systems: cardiovascular, sleep, breathing, autonomic '.
    'recovery and metabolism, each with a status (good / warning / serious / critical), the '.
    'facts behind it and a concrete recommendation. Carries the early illness pattern across '.
    'resting heart rate, nightly breathing and HRV. These are the rules the dashboard and the '.
    'morning briefing run on, so call this instead of inventing thresholds in SQL: a verdict '.
    'derived ad hoc will contradict what the athlete was told this morning.'
)]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetInsightsTool extends LoggedTool
{
    use ChecksConnectorPermissions;

    /**
     * The same windows the dashboard render feeds the rules: the baselines
     * reach back 31 days and the illness pattern needs 14 values inside
     * that, so a shorter history would silently disable it.
     */
    private const HISTORY_DAYS = 120;

    public function execute(Request $request, GarminData $garmin, Insights $insights, TrainingLoad $load): Response
    {
        if ($deny = $this->denyUnless($this->settings()->share_health_data, 'share_health_data')) {
            return $deny;
        }

        $days = $garmin->days(self::HISTORY_DAYS);
        $sleep = $garmin->sleep(self::HISTORY_DAYS);
        $hrv = $garmin->hrv(self::HISTORY_DAYS);
        $latestHrv = $hrv->sortBy('date')->last();

        if ($days->isEmpty() && $sleep->isEmpty()) {
            return Response::json([
                'has_data' => false,
                'hint' => 'The mirror holds neither daily metrics nor nights yet, so there is nothing to read a body system off.',
            ]);
        }

        $acwr = $load->acwr(
            $garmin->trainingStatus(120),
            $load->series($garmin->activities(400), 7)['dailyLoad'],
        );

        $systems = $insights->systems(
            $days, $sleep, $hrv, $latestHrv, $acwr,
            $insights->sleepConsistency($sleep),
            $garmin->heartProfile(),
        );

        $illness = $insights->illnessWarning($days, $sleep, $latestHrv);
        $systems = $insights->applyIllnessWarning($systems, $illness);

        // Weight and fitness age live in this system and nowhere else in
        // the payload, so the body-metrics switch closes exactly it while
        // the other four keep answering.
        if ($this->settings()->share_body_metrics) {
            $systems['metabolism'] = $insights->metabolism(
                $garmin->fitnessAge(),
                $garmin->bodyComp()->last(),
                $days->sortBy('date')->last(),
                $days,
            );
        }

        return Response::json(array_filter([
            'has_data' => true,
            // The labels and recommendations below are the athlete's own
            // interface language, because they are the same sentences the
            // dashboard and the morning briefing show. Say them in the
            // language of the chat; the wording is what matters, not which
            // language it arrived in.
            'interface_language' => app()->getLocale(),
            'systems' => $this->readable($systems),
            'illness_pattern' => $illness === null ? null : [
                'status' => $illness['status'],
                'message' => $illness['message'],
                'criteria_met' => array_keys(array_filter($illness['criteria'])),
            ],
            'notes' => [
                'status is one of good / warning / serious / critical; the recommendation is the app\'s own, phrased for the athlete.',
                'illness_pattern is absent unless the resting heart rate sits at least 5 bpm above its 30-day baseline and a second marker agrees. It is a pattern hint, never a diagnosis, and it must be repeated as one.',
                'Baselines exclude the last two days, so an onset cannot drag the baseline toward itself.',
                'A missing fact means the watch never recorded it, not a zero.',
            ],
        ], fn ($v) => $v !== null));
    }

    /**
     * The systems as a chat can use them: the drawing material (spark
     * series and its caption) goes, the facts flatten from label/value
     * pairs into one object per system. Thirty sparkline points per system
     * would be five hundred numbers nobody reads aloud.
     *
     * @param  array<string, array<string, mixed>>  $systems
     * @return array<string, array<string, mixed>>
     */
    private function readable(array $systems): array
    {
        return collect($systems)->map(fn (array $system): array => array_filter([
            'label' => $system['label'],
            'status' => $system['status'],
            'value' => $system['value'] === '–' ? null : $system['value'],
            'recommendation' => $system['recommendation'],
            'facts' => array_column($system['facts'] ?? [], 'value', 'label'),
            'hr_zones' => $system['zones'] ?? null,
            'help' => $system['help'] ?? null,
        ], fn ($v) => $v !== null && $v !== []))->all();
    }
}
