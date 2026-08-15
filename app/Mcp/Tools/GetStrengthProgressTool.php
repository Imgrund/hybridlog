<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Garmin\GarminData;
use App\Garmin\StrengthProgression;
use App\Mcp\Concerns\ChecksConnectorPermissions;
use App\Mcp\LoggedTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(
    'Week by week strength progression per exercise category as the dashboard computes it: reps, '.
    'tonnage where the sets really carry a weight, the weekly top weight, the bests inside the '.
    'window and a "holds at X kg for N weeks" reading. Answers "am I getting stronger". Call this '.
    'instead of summing strength_sets in SQL: weights are grams, most categories come back '.
    'UNKNOWN, and a naive sum mixes weightless reps into a tonnage that was never lifted.'
)]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
class GetStrengthProgressTool extends LoggedTool
{
    use ChecksConnectorPermissions;

    /** ISO weeks reported when the caller names none. */
    private const DEFAULT_WEEKS = 8;

    public function schema(JsonSchema $schema): array
    {
        return [
            'weeks' => $schema->integer()
                ->description('How many trailing ISO weeks to report, the running one included (default 8, max 26).'),
        ];
    }

    public function execute(Request $request, GarminData $garmin, StrengthProgression $model): Response
    {
        if ($deny = $this->denyUnless($this->settings()->share_health_data, 'share_health_data')) {
            return $deny;
        }

        $validated = $request->validate(['weeks' => ['nullable', 'integer', 'min:2', 'max:26']]);
        $weeks = (int) ($validated['weeks'] ?? self::DEFAULT_WEEKS);

        // A week of sets either side of the window, so the model can place
        // the running week and still see the history it dates against.
        $progression = $model->weekly($garmin->strengthSets(($weeks + 1) * 7), $weeks);

        if ($progression['categories'] === []) {
            return Response::json([
                'has_data' => false,
                'hint' => 'No strength sets in the mirror for this window. Either nothing was recorded set by set, or the sessions were tracked as an activity without sets, in which case get-training-load-tool is the honest reading.',
            ]);
        }

        return Response::json([
            'has_data' => true,
            'weeks' => $progression['weeks'],
            'running_week' => $progression['runningIndex'] !== null
                ? $progression['weeks'][$progression['runningIndex']]
                : null,
            'sessions' => $progression['sessions'],
            'any_weight_recorded' => $progression['anyWeight'],
            'categories' => array_map($this->category(...), $progression['categories']),
            'notes' => [
                'Every per-week array is aligned with weeks: index 0 is the oldest week listed there.',
                'reps is the one volume measure every category can report. kg is present only where the majority of a category\'s sets carry a recorded weight; where it is absent, the work happened but the watch logged no weight, which is not the same as light.',
                'exercise_category UNKNOWN ("Unclassified") is Garmin failing to name the movement, not a category of training. It is usually the largest bucket in circuit work.',
                'holds_at says the weekly top sat on the same weight for that many trained weeks. It is an observation about a habit, not a verdict, and it stays silent through pauses and after any change.',
                'A rep count is what the watch counted, and it counts poorly in fast circuits. Read the trend rather than the absolute number.',
            ],
        ]);
    }

    /**
     * One category as a chat reads it: the enum key humanised, the series
     * kept, the null-only kilogram fields dropped rather than printed as
     * a row of nulls a model would then explain.
     *
     * @param  array<string, mixed>  $category
     * @return array<string, mixed>
     */
    private function category(array $category): array
    {
        return array_filter([
            'category' => StrengthProgression::label($category['key']),
            'category_key' => $category['key'],
            'sets' => $category['sets'],
            'weight_tracked' => $category['weighted'],
            'reps_per_week' => $category['reps'],
            'kg_volume_per_week' => $category['kg'],
            // A row of nulls is not a weight series, and a model handed one
            // will try to say something about it.
            'top_kg_per_week' => array_filter($category['topKg'], fn (?float $kg) => $kg !== null) === []
                ? null
                : $category['topKg'],
            'current_top_kg' => $category['currentTopKg'],
            'best_set_kg' => $category['bestSetKg'],
            'best_week_kg' => $category['bestWeekKg'],
            'best_week_reps' => $category['bestWeekReps'],
            'last_full_week_reps' => $category['lastFullWeekReps'],
            'holds_at' => $category['stagnation'] === null ? null : [
                'kg' => $category['stagnation']['kg'],
                'weeks' => $category['stagnation']['weeks'],
            ],
        ], fn ($v) => $v !== null);
    }
}
