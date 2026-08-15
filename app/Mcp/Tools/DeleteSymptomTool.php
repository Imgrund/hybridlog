<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ChecksConnectorPermissions;
use App\Mcp\LoggedTool;
use App\Models\SymptomLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Description(
    'Delete a symptom log entry by id when the user corrects or retracts it. Ids come from the '.
    'log-symptom-tool response or from get-health-summary (last 3 days). Log the corrected '.
    'entry afterwards when there is one.'
)]
#[IsDestructive]
#[IsIdempotent]
#[IsOpenWorld(false)]
class DeleteSymptomTool extends LoggedTool
{
    use ChecksConnectorPermissions;

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Id of the entry to delete.')->required(),
        ];
    }

    public function execute(Request $request): Response
    {
        $user = $this->actingUser();

        if ($deny = $this->denyUnless($this->settings()->allow_symptoms, 'allow_symptoms')) {
            return $deny;
        }

        $validated = $request->validate([
            'id' => ['required', 'integer'],
        ]);

        // Scoped lookup instead of an exists rule: an id belonging to
        // another user must answer "not found", never "exists but
        // forbidden": the ids themselves are nobody else's business.
        $entry = SymptomLog::for($user)->find($validated['id']);

        if ($entry === null) {
            return Response::error(__('No symptom entry with id :id.', ['id' => $validated['id']]));
        }

        $entry->delete();

        return Response::json([
            'deleted' => true,
            'entry' => [
                'id' => $entry->id,
                'date' => $entry->date,
                'symptom' => $entry->symptom,
            ],
        ]);
    }
}
