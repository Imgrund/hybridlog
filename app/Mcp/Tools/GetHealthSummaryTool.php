<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Garmin\GarminData;
use App\Garmin\MuscleFreshness;
use App\Garmin\TrainingLoad;
use App\Mcp\Concerns\ChecksConnectorPermissions;
use App\Mcp\LoggedTool;
use App\Models\SymptomLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(
    'Curated snapshot of the athlete\'s current state: readiness, HRV, sleep, recent activities '.
    'with training load. Fastest way to ground a coaching conversation; use query-health-data '.
    'for anything deeper.'
)]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetHealthSummaryTool extends LoggedTool
{
    use ChecksConnectorPermissions;

    public function schema(JsonSchema $schema): array
    {
        return [
            'days' => $schema->integer()
                ->description('Lookback window in days (default 7, max 30).'),
        ];
    }

    public function execute(Request $request, GarminData $garmin, TrainingLoad $load, MuscleFreshness $muscles): Response
    {
        if ($deny = $this->denyUnless($this->settings()->share_health_data, 'share_health_data')) {
            return $deny;
        }

        $validated = $request->validate(['days' => ['nullable', 'integer', 'min:1', 'max:30']]);
        $days = (int) ($validated['days'] ?? 7);

        $payload = [
            'window_days' => $days,
            // Anything written back to the dashboard (symptom labels) is
            // read there, not here, so it follows this language rather
            // than the one the chat is held in.
            'interface_language' => app()->getLocale(),
            'last_fetch' => $garmin->latestFetch(),
            // One shared verdict with the dashboard header: whether the
            // mirror is trustworthy right now, and if not, why and what
            // fixes it (expired login vs. dead fetch job vs. unsynced
            // watch). Surface the hint per the server instructions.
            'data_status' => $garmin->dataStatus()->toMcpArray(),
            'readiness' => $garmin->readiness($days)
                // score/level/recovery_time_h are the morning snapshot; the
                // current_* fields (today only) reflect the watch state after
                // the day's workouts and are the ones to reason about "now".
                ->map(fn ($r) => array_filter([
                    'date' => $r->date,
                    'score' => $r->score,
                    'level' => $r->level,
                    'recovery_time_h' => $r->recovery_time_h,
                    'current_score' => $r->current_score ?? null,
                    'current_level' => $r->current_level ?? null,
                    'current_recovery_time_h' => $r->current_recovery_time_h ?? null,
                    'current_at' => $r->current_at ?? null,
                ], fn ($v) => $v !== null)),
            'hrv' => $garmin->hrv($days)
                ->map(fn ($h) => ['date' => $h->date, 'last_night_avg' => $h->last_night_avg, 'weekly_avg' => $h->weekly_avg, 'status' => $h->status]),
            'sleep' => $garmin->sleep($days)
                ->map(fn ($s) => ['date' => $s->date, 'score' => $s->score, 'duration_h' => $s->duration_s !== null ? round($s->duration_s / 3600, 1) : null, 'start' => $s->start_local, 'end' => $s->end_local]),
            'activities' => $garmin->activities($days)
                ->map(fn ($a) => ['date' => $a->date, 'type' => $a->type_key, 'name' => $a->name, 'duration_min' => $a->duration_s !== null ? (int) round($a->duration_s / 60) : null, 'avg_hr' => $a->avg_hr, 'training_load' => $a->training_load !== null ? round((float) $a->training_load) : null]),
            // The load model and the muscle map in one glance, so a
            // coaching answer can start from the dashboard's own numbers.
            // Depth lives in get-training-load-tool and get-muscle-map-tool.
            'training' => $this->training($garmin, $load, $muscles),
            'symptoms' => $this->symptoms(),
        ];
        foreach (['training', 'symptoms'] as $optional) {
            if ($payload[$optional] === null) {
                unset($payload[$optional]);
            }
        }

        return Response::json($payload);
    }

    /**
     * Today's model values plus the three least fresh zones, the same
     * numbers the dashboard's hero and map show. Null while the mirror
     * holds no loadable activity: no model, no section.
     */
    private function training(GarminData $garmin, TrainingLoad $load, MuscleFreshness $muscles): ?array
    {
        $activities = $garmin->activities(400);
        $series = $load->series($activities, 7);
        if ($series['dates'] === []) {
            return null;
        }

        $acwr = $load->acwr($garmin->trainingStatus(120), $series['dailyLoad']);

        $freshness = $muscles->compute($garmin->strengthSets(90), $activities);
        $leastFresh = collect($freshness['zones'] ?? [])
            ->filter(fn (array $zone) => $zone['hasData'])
            ->sortBy('freshness')
            ->take(3)
            ->map(fn (array $zone, string $key) => [
                'zone' => $key,
                'label' => MuscleFreshness::zoneLabels()[$key] ?? $key,
                'freshness' => $zone['freshness'],
            ])
            ->values()
            ->all();

        return array_filter([
            'ctl_fitness' => end($series['ctl']),
            'atl_fatigue' => end($series['atl']),
            'tsb_form' => end($series['tsb']),
            'acwr' => $acwr['value'],
            'acwr_status' => TrainingLoad::acwrStatus($acwr['value']),
            'least_fresh_zones' => $leastFresh !== [] ? $leastFresh : null,
            'hint' => 'Depth lives in get-training-load-tool and get-muscle-map-tool; both return the dashboard\'s own numbers.',
        ], fn ($v) => $v !== null);
    }

    /**
     * Self-reported symptoms of the last 3 days with ids, so corrections
     * can name an entry. Null (section absent) while the toggle is off:
     * disabled means the AI does not get to see them either.
     */
    private function symptoms(): ?array
    {
        if (! $this->settings()->allow_symptoms) {
            return null;
        }

        return SymptomLog::for($this->actingUser())
            ->where('date', '>=', now()->subDays(2)->toDateString())
            ->orderBy('logged_at')
            ->get()
            ->map(fn ($s) => array_filter([
                'id' => $s->id,
                'date' => $s->date,
                'time' => $s->logged_at?->format('H:i'),
                'symptom' => $s->symptom,
                'severity' => $s->severity,
                'note' => $s->note,
            ], fn ($v) => $v !== null))
            ->values()
            ->all();
    }
}
