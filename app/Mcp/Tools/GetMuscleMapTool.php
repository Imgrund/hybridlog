<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Garmin\GarminData;
use App\Garmin\MuscleFreshness;
use App\Mcp\Concerns\ChecksConnectorPermissions;
use App\Mcp\LoggedTool;
use App\Models\SymptomLog;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(
    'The muscle map as the dashboard computes it: per-zone freshness (0 = just hammered, 100 = '.
    'fully fresh) with exponential per-zone decay, what each zone saw in the last 7 and 28 days '.
    '(volume, fractional sets, measured share, the sessions behind it), and a recovery forecast. '.
    'Call this instead of re-deriving muscle load '.
    'in SQL: the model self-calibrates against the athlete\'s own history, and an ad-hoc SQL '.
    'version will disagree with what the athlete sees on their map.'
)]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetMuscleMapTool extends LoggedTool
{
    use ChecksConnectorPermissions;

    public function execute(Request $request, GarminData $garmin, MuscleFreshness $model): Response
    {
        if ($deny = $this->denyUnless($this->settings()->share_health_data, 'share_health_data')) {
            return $deny;
        }

        // The exact rows the dashboard render feeds the model: 90 days of
        // sets, 400 days of activities (ChartBundle::ACTIVITY_HISTORY_DAYS).
        // Same input, same numbers: the whole point of this tool is that
        // map and chat can never disagree about a zone.
        $freshness = $model->compute($garmin->strengthSets(90), $garmin->activities(400));

        if (! $freshness['hasData']) {
            return Response::json([
                'has_data' => false,
                'hint' => 'No mapped strength sets or loadable activities in the mirror yet, so there is no map to read.',
            ]);
        }

        $labels = MuscleFreshness::zoneLabels();

        $zones = [];
        foreach ($freshness['zones'] as $zone => $data) {
            // A zone without a single load event stays two fields long:
            // its windows would only spell out zeroes, and freshness must
            // not be printed for it (unknown is not "fresh").
            if (! $data['hasData']) {
                $zones[$zone] = ['label' => $labels[$zone] ?? $zone, 'has_data' => false];

                continue;
            }

            $zones[$zone] = array_filter([
                'label' => $labels[$zone] ?? $zone,
                'has_data' => true,
                'freshness' => $data['freshness'],
                'last_trained' => $data['lastTrained'],
                'recovers_at' => $data['recoversAt'],
                'half_life_hours' => MuscleFreshness::halfLife($zone),
                'windows' => collect($data['windows'])->map(fn (array $w) => array_filter([
                    'volume' => $w['volume'],
                    'fractional_sets' => $w['sets'],
                    'measured_share_pct' => $w['measuredShare'],
                    'training_days' => $w['days'],
                    'sessions' => $w['sessions'] !== [] ? $w['sessions'] : null,
                ], fn ($v) => $v !== null))->all(),
            ], fn ($v) => $v !== null);
        }

        $payload = [
            'has_data' => true,
            'zones' => $zones,
            // What the volume lens paints its darkest step against, per
            // window: the loudest zone's volume, a relative anchor only.
            'volume_ceiling' => $freshness['volumeCeiling'] ?? [],
            'symptoms' => $this->symptoms(),
            'notes' => [
                'freshness carries decay and answers "what is left in the tank"; windows carry none and answer "where did the training go". A zone can be fresh again and still undertrained this week.',
                'has_data=false on a zone means Garmin never reported load for it: unknown, not untrained, and never "fresh".',
                'measured_share_pct says how much of a window\'s volume came from recorded sets rather than being spread from a whole activity\'s training load; low shares mean the zone split is an estimate.',
                'fractional_sets counts a set for a zone as much as the exercise loads it (the dose-response counting); whole-activity fallbacks contribute volume but never sets, so circuit-heavy blocks read low in sets on purpose.',
                'recovers_at is when the zone is projected back at 90 % freshness; absent means already recovered or more than a week out.',
            ],
        ];

        if ($payload['symptoms'] === null) {
            unset($payload['symptoms']);
        }

        return Response::json($payload);
    }

    /**
     * The reported complaints the map overlays on its zones, one marker
     * per zone, worst first. Null (section absent) while the toggle is
     * off: disabled means the AI does not get to see them either.
     */
    private function symptoms(): ?array
    {
        if (! $this->settings()->allow_symptoms) {
            return null;
        }

        $byZone = SymptomLog::byZone($this->actingUser());

        return $byZone === [] ? null : $byZone;
    }
}
