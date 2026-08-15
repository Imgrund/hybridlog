<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ChecksConnectorPermissions;
use App\Mcp\LoggedTool;
use App\Models\SymptomLog;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Description(
    'Log a symptom or complaint ("kratziger Hals", "Kopfschmerzen"), but ONLY when the user '.
    'mentions it unprompted. Capture it in passing with a one-sentence confirmation and move '.
    'on; NEVER ask the user how they feel or whether they have symptoms. severity is optional: '.
    '1 mild, 2 moderate, 3 severe. Pass body_zone whenever the user named a place ("knee hurts", '.
    '"sore hamstrings") so the report lands on the body map next to that zone\'s training load; '.
    'omit it for anything without a place ("scratchy throat"). Entries surface on the dashboard '.
    'as context on the illness early-warning banner and, with a body_zone, as a marker on the '.
    'body map. To correct an entry, delete it (delete-symptom-tool) and log the corrected one.'
)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
class LogSymptomTool extends LoggedTool
{
    use ChecksConnectorPermissions;

    public function schema(JsonSchema $schema): array
    {
        return [
            'symptom' => $schema->string()
                ->description('The symptom, short, in the user\'s language, e.g. "scratchy throat".')
                ->required(),
            'severity' => $schema->integer()
                ->description('Optional severity: 1 mild, 2 moderate, 3 severe.'),
            'note' => $schema->string()
                ->description('Optional short context, e.g. "since waking up".'),
            'body_zone' => $schema->string()
                ->description(
                    'Optional body region, only when the user named one. Joint regions: KNEE, HIP, '.
                    'GROIN, ANKLE, FOOT, ACHILLES, SHIN, SHOULDER, ELBOW, WRIST, HAND, NECK, '.
                    'UPPER_ARM. Muscle zones: CHEST, FRONT_DELTOIDS, BACK_DELTOIDS, BICEPS, TRICEPS, '.
                    'FOREARM, ABS, OBLIQUES, QUADRICEPS, HAMSTRING, GLUTEAL, CALVES, TRAPEZIUS, '.
                    'UPPER_BACK, LOWER_BACK, ABDUCTORS. Sets the marker on the body map.'
                ),
            'side' => $schema->string()
                ->description('Optional side when the user named one: left, right or both.'),
            'date' => $schema->string()
                ->description("Day YYYY-MM-DD; omit for today. Use for late mentions ('since yesterday')."),
            'time' => $schema->string()->description('Time HH:MM local; omit for now.'),
        ];
    }

    public function execute(Request $request): Response
    {
        $user = $this->actingUser();

        if ($deny = $this->denyUnless($this->settings()->allow_symptoms, 'allow_symptoms')) {
            return $deny;
        }

        $validated = $request->validate([
            'symptom' => ['required', 'string', 'max:255'],
            'severity' => ['nullable', 'integer', 'min:1', 'max:3'],
            'note' => ['nullable', 'string', 'max:255'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
            'body_zone' => ['nullable', 'string', Rule::in(SymptomLog::regions())],
            'side' => ['nullable', Rule::in(['left', 'right', 'both'])],
        ]);

        $date = $validated['date'] ?? now()->toDateString();

        // Late mentions without a time land mid-day: honest enough for a
        // per-day context line, without inventing a precise timestamp.
        $loggedAt = match (true) {
            isset($validated['time']) => Carbon::parse($date.' '.$validated['time']),
            isset($validated['date']) && $validated['date'] !== now()->toDateString() => Carbon::parse($date.' 12:00'),
            default => now(),
        };

        $entry = SymptomLog::create([
            'user_id' => $user->id,
            'date' => $date,
            'logged_at' => $loggedAt,
            'symptom' => $validated['symptom'],
            'severity' => $validated['severity'] ?? null,
            'note' => $validated['note'] ?? null,
            'body_zone' => $validated['body_zone'] ?? null,
            'side' => $validated['side'] ?? null,
        ]);

        return Response::json([
            'logged' => true,
            'entry_id' => $entry->id,
            'recent_days' => SymptomLog::for($user)->where('date', '>=', now()->subDays(2)->toDateString())->count(),
        ]);
    }
}
